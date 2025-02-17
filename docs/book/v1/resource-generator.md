# Generating Resources from PHP Objects

In the previous chapter, [we discussed links and resources](links-and-resources.md).
The primitive objects allow us to create representations easily, but do not
answer one critical question: how can we create resources based on existing PHP
object types?

To answer that question, we provide two related features: metadata, and a
resource generator.

## Metadata

Metadata allows you to detail the requirements for generating a HAL
representation of a PHP object. Metadata might include:

- The PHP class name to represent.
- A URI to use for the generated resource's self relational link.
- Alternately, routing information to use with the `LinkGenerator`.
- A laminas-hydrator extractor to use to serialize the object to a representation.
- Whether or not the resource is a collection, and, if so, whether pagination is
  handled as a path parameter or a query string argument, the name of the
  parameter, etc.

All metadata types inherit from `Mezzio\Hal\Metadata\AbstractMetadata`,
which defines a single method, `getClass()`, for retrieving the name of the PHP
class to represent; all metadata are expected to inherit from this class.

The component also provides four concrete metadata types, requiring the
following information:

- `Mezzio\Hal\Metadata\RouteBasedCollectionMetadata`:
    - string `$class`
    - string `$collectionRelation`
    - string `$route`
    - string `$paginationParam = 'page'` (name of the parameter indicating the
      current page of results)
    - string `$paginationParamType = self::TYPE_QUERY` (one of "query" or "placeholder")
    - array `$routeParams = []` (associative array of substitutions to use with
      the designated route)
    - array `$queryStringArguments = []` (associative array of query string
      arguments to include in the generated URI)
- `Mezzio\Hal\Metadata\RouteBasedResourceMetadata`:
    - string `$class`
    - string `$route`
    - string `$extractor` (string service name of the laminas-hydrator hydrator to
      use for extracting data from the instance)
    - string `$resourceIdentifier = 'id'` (name of the property uniquely
      identifying the resource)
    - string `$routeIdentifierPlaceholder = 'id'` (name of the routing parameter
      that maps to the resource identifier; deprecated since 1.4.0 in favor of
      using the `$identifiersToPlacholdersMapping`)
    - array `$routeParams = []` (associative array of additional routing
      parameters to substitute when generating the URI)
    - array `$identifiersToPlacholdersMapping = []` (associative array mapping resource properties to route parameters, for use when generating the URI; available since 1.4.0)
- `Mezzio\Hal\Metadata\UrlBasedCollectionMetadata`:
    - string `$class`
    - string `$collectionRelation`
    - string `$url`
    - string `$paginationParam = 'page'` (name of the parameter indicating the
      current page of results)
    - string `$paginationParamType = self::TYPE_QUERY` (one of "query" or "placeholder")
- `Mezzio\Hal\Metadata\UrlBasedResourceMetadata`
    - string `$class`
    - string `$url`
    - string `$extractor` (string service name of the laminas-hydrator hydrator to
      use for extracting data from the instance)

We aggregate metadata in a `Mezzio\Hal\Metadata\MetadataMap` instance:

```php
$bookMetadata = new RouteBasedResourceMetadata(
    Book::class,
    'book',
    ObjectPropertyHydrator::class
);
$booksMetadata = new RouteBasedCollectionMetadata(
    BookCollection::class,
    'book',
    'books',
);

$metadataMap = new MetadataMap();
$metadataMap->add($bookMetadata);
$metadataMap->add($booksMetadata);
```

### Configuration-based metadata

To automate generation of the `MetadataMap`, we provide
`Mezzio\Hal\Metadata\MetadataMapFactory`. This factory may be used with any
[PSR-11](https://www.php-fig.org/psr/psr-11/) container. It utilizes the `config`
service, and pulls its configuration from a key named after the
`Mezzio\Hal\Metadata\MetadataMap` class.

Each item in the map will be an associative array. The member `__class__` will
describe which metadata class to create, and the remaining properties will then
be used to generate an instance. As an example, the above could be configured as
follows:

```php
use Api\Books\Book;
use Api\Books\BookCollection;
use Mezzio\Hal\Metadata\MetadataMap;
use Mezzio\Hal\Metadata\RouteBasedCollectionMetadata;
use Mezzio\Hal\Metadata\RouteBasedResourceMetadata;
use Laminas\Hydrator\ObjectProperty;

return [
    'Mezzio\Hal\Metadata\MetadataMap' => [
        [
            '__class__' => RouteBasedResourceMetadata::class,
            'resource_class' => Book::class,
            'route' => 'book',
            'extractor' => ObjectProperty::class,
        ],
        [
            '__class__' => RouteBasedCollectionMetadata::class,
            'collection_class' => BookCollection::class,
            'collection_relation' => 'book',
            'route' => 'books',
        ],
    ],
];
```

The rest of the parameters follow underscore delimiter naming convention:

- `RouteBasedResourceMetadata::class`
    - `resource_identifier` (name of the property uniquely identifying the resource)
    - `route_identifier_placeholder` (name of the routing parameter that maps to the resource identifier. Deprecated since 1.4.0; use the `identifiers_to_placeholders_mapping` setting instead)
    - `route_params` (associative array of substitutions to use with the designated route)
    - `identifiers_to_placeholders_mapping` (associative array mapping resource properties to routing parameters, for use when generating the URI; since 1.4.0)
- `RouteBasedCollectionMetadata::class`
    - `pagination_param`  (name of the parameter indicating the current page of results)
    - `pagination_param_type` (one of "query" or "placeholder")
    - `route_params` (associative array of substitutions to use with the designated route)
    - `query_string_arguments` (associative array of additional routing parameters to substitute when generating the URI)
- `UrlBasedResourceMetadata::class`
    - `resource_class`
    - `url`
    - `extractor`
- `UrlBasedCollectionMetadata::class`
    - `collection_class`
    - `collection_relation`
    - `url`
    - `pagination_param` (name of the parameter indicating the current page of results)
    - `pagination_param_type` (one of "query" or "placeholder")

## ResourceGenerator

Once you have defined the metadata for the various objects you will represent in
your API, you can start generating resources.

`Mezzio\Hal\ResourceGenerator` has the following constructor:

```php
public function __construct(
    Mezzio\Hal\Metadata\MetadataMap $metadataMap,
    Psr\Container\ContainerInterface $hydrators,
    Mezzio\Hal\LinkGenerator $linkGenerator
) {
```

We described the `MetadataMap` in the previous section, and the `LinkGenerator`
in the [previous chapter](links-and-resources.md#route-based-link-uris).

Hydrators are defind in the [laminas-hydrator component](https://docs.laminas.dev/laminas-hydrator/),
and are objects which can _hdyrate_ associative arrays to object instances and
_extract_ associative arrays from object instances. Generally speaking, the
`$hydrators` instance may be any PSR-11 container, but you will generally want
to use the `Laminas\Hydrator\HydratorPluginManager`.

Once you have your instance created, you can start generating resources:

```php
$resource = $resourceGenerator->fromObject($book, $request);
```

(Where `$request` is a `Psr\Http\Message\ServerRequestInterface` instance; the
instance is passed along to the `LinkGenerator` in order to generate route-based
URIs for `Link` instances.)
