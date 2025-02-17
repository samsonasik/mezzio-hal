# Generating Custom Resources

The `ResourceGenerator` allows composing `Mezzio\Hal\ResourceGenerator\StrategyInterface`
instances. The `StrategyInterface` defines the following:

```php
namespace Mezzio\Hal\ResourceGenerator;

use Psr\Http\Message\ServerRequestInterface;
use Mezzio\Hal\HalResource;
use Mezzio\Hal\Metadata;
use Mezzio\Hal\ResourceGenerator;

interface StrategyInterface
{
    /**
     * @param object $instance Instance from which to create Resource.
     * @throws Exception\UnexpectedMetadataTypeException for metadata types the
     *     strategy cannot handle.
     */
    public function createResource(
        $instance,
        Metadata\AbstractMetadata $metadata,
        ResourceGenerator $resourceGenerator,
        ServerRequestInterface $request,
        int $depth = 0
    ) : HalResource;
}
```

When you register a strategy, you will map a metadata type to the strategy; the
`ResourceGenerator` will then call your strategy whenever it encounteres
metadata of that type.

```php
$resourceGenerator->addStrategy(CustomMetadata::class, CustomStrategy::class);

// or:
$resourceGenerator->addStrategy(CustomMetadata::class, $strategyInstance);
```

You can also add your strategies via the configuration:

```php
return [
    'mezzio-hal' => [
        'resource-generator' => [
            'strategies' => [
                CustomMetadata::class => CustomStrategy::class,
            ],
        ],
    ],
];
```

If a strategy already is mapped for the given metadata type, this method will
override it.

To facilitate common operations, this library provides two traits,
`Mezzio\Hal\ResourceGenerator\ExtractCollectionTrait` and
`Mezzio\Hal\ResourceGenerator\ExtractInstanceTrait`; inspect these if you
decide to write your own strategies.

In order for the `MetadataMap` to be able to use your `CustomMetadata` you need to register
a factory (implementing `Mezzio\Hal\Metadata\MetadataFactoryInterface`) for it.
You can register them via the configuration:

```php
return [
    'mezzio-hal' => [
        'metadata-factories' => [
            CustomMetadata::class => CustomMetadataFactory::class,
        ],
    ],
];
```
