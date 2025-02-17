<?php

declare(strict_types=1);

namespace MezzioTest\Hal;

use ArrayIterator;
use Laminas\Paginator\Adapter\ArrayAdapter;
use Laminas\Paginator\Paginator;
use Mezzio\Hal\Exception\InvalidObjectException;
use Mezzio\Hal\Exception\InvalidStrategyException;
use Mezzio\Hal\Exception\UnknownMetadataTypeException;
use Mezzio\Hal\HalResource;
use Mezzio\Hal\Link;
use Mezzio\Hal\LinkGenerator;
use Mezzio\Hal\Metadata;
use Mezzio\Hal\ResourceGenerator;
use Mezzio\Hal\ResourceGenerator\Exception\OutOfBoundsException;
use Mezzio\Hal\ResourceGeneratorInterface;
use MezzioTest\Hal\TestAsset\TestMetadata;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;

use function array_key_exists;
use function class_parents;

/**
 * @todo Create tests for cases where resources embed other resources.
 */
class ResourceGeneratorTest extends TestCase
{
    use Assertions;

    use PHPUnitDeprecatedAssertions;

    use ProphecyTrait;

    /**
     * @var ObjectProphecy|ServerRequestInterface
     * @psalm-var ObjectProphecy<ServerRequestInterface>
     */
    private $request;

    /**
     * @var ObjectProphecy|ContainerInterface
     * @psalm-var ObjectProphecy<ContainerInterface>
     */
    private $hydrators;

    /** @var ObjectProphecy|LinkGenerator */
    private $linkGenerator;

    /**
     * @var ObjectProphecy|Metadata\MetadataMap
     * @psalm-var ObjectProphecy<Metadata\MetadataMap>
     */
    private $metadataMap;

    /** @var ResourceGenerator */
    private $generator;

    public function setUp(): void
    {
        $this->request = $this->prophesize(ServerRequestInterface::class);
        $this->request->getQueryParams()->willReturn([]);
        $this->hydrators     = $this->prophesize(ContainerInterface::class);
        $this->linkGenerator = $this->prophesize(LinkGenerator::class);
        $this->metadataMap   = $this->prophesize(Metadata\MetadataMap::class);
        $this->generator     = new ResourceGenerator(
            $this->metadataMap->reveal(),
            $this->hydrators->reveal(),
            $this->linkGenerator->reveal()
        );

        $this->generator->addStrategy(
            Metadata\RouteBasedResourceMetadata::class,
            ResourceGenerator\RouteBasedResourceStrategy::class
        );

        $this->generator->addStrategy(
            Metadata\RouteBasedCollectionMetadata::class,
            ResourceGenerator\RouteBasedCollectionStrategy::class
        );

        $this->generator->addStrategy(
            Metadata\UrlBasedCollectionMetadata::class,
            ResourceGenerator\UrlBasedCollectionStrategy::class
        );

        $this->generator->addStrategy(
            Metadata\UrlBasedResourceMetadata::class,
            ResourceGenerator\UrlBasedResourceStrategy::class
        );
    }

    public function testResourceGeneratorImplementsInterface(): void
    {
        $this->assertInstanceOf(ResourceGeneratorInterface::class, $this->generator);
    }

    public function testCanGenerateResourceWithSelfLinkFromArrayData(): void
    {
        $data = [
            'foo' => 'bar',
            'bar' => 'baz',
        ];

        $this->hydrators->has('config')->willReturn(false);

        $this->linkGenerator->fromRoute()->shouldNotBeCalled();
        $this->metadataMap->has()->shouldNotBeCalled();

        $resource = $this->generator->fromArray($data, '/api/example');
        $this->assertInstanceOf(HalResource::class, $resource);

        $self = $this->getLinkByRel('self', $resource);
        $this->assertLink('self', '/api/example', $self);

        $this->assertEquals($data, $resource->getElements());
    }

    public function testCanGenerateUrlBasedResourceFromObjectDefinedInMetadataMap(): void
    {
        $instance      = new TestAsset\FooBar();
        $instance->id  = 'XXXX-YYYY-ZZZZ';
        $instance->foo = 'BAR';
        $instance->bar = 'BAZ';

        $metadata = new Metadata\UrlBasedResourceMetadata(
            TestAsset\FooBar::class,
            '/api/foo/XXXX-YYYY-ZZZZ',
            self::getObjectPropertyHydratorClass()
        );

        $this->metadataMap->has(TestAsset\FooBar::class)->willReturn(true);
        $this->metadataMap->get(TestAsset\FooBar::class)->willReturn($metadata);

        $hydratorClass = self::getObjectPropertyHydratorClass();

        $this->hydrators->get($hydratorClass)->willReturn(new $hydratorClass());
        $this->linkGenerator->fromRoute()->shouldNotBeCalled();

        $resource = $this->generator->fromObject($instance, $this->request->reveal());

        $this->assertInstanceOf(HalResource::class, $resource);

        $self = $this->getLinkByRel('self', $resource);
        $this->assertLink('self', '/api/foo/XXXX-YYYY-ZZZZ', $self);

        $this->assertEquals([
            'id'       => 'XXXX-YYYY-ZZZZ',
            'foo'      => 'BAR',
            'bar'      => 'BAZ',
            'children' => null,
        ], $resource->getElements());
    }

    public function testCanGenerateRouteBasedResourceFromObjectDefinedInMetadataMap(): void
    {
        $instance      = new TestAsset\FooBar();
        $instance->id  = 'XXXX-YYYY-ZZZZ';
        $instance->foo = 'BAR';
        $instance->bar = 'BAZ';

        $metadata = new Metadata\RouteBasedResourceMetadata(
            TestAsset\FooBar::class,
            'foo-bar',
            self::getObjectPropertyHydratorClass(),
            'id',
            ['test' => 'param'],
            ['id' => 'foo_bar_id']
        );

        $this->metadataMap->has(TestAsset\FooBar::class)->willReturn(true);
        $this->metadataMap->get(TestAsset\FooBar::class)->willReturn($metadata);

        $hydratorClass = self::getObjectPropertyHydratorClass();

        $this->hydrators->get($hydratorClass)->willReturn(new $hydratorClass());
        $this->linkGenerator
            ->fromRoute(
                'self',
                $this->request->reveal(),
                'foo-bar',
                Argument::that(function (array $params) {
                    return array_key_exists('foo_bar_id', $params)
                        && array_key_exists('test', $params)
                        && $params['foo_bar_id'] === 'XXXX-YYYY-ZZZZ'
                        && $params['test'] === 'param';
                })
            )
            ->willReturn(new Link('self', '/api/foo-bar/XXXX-YYYY-ZZZZ'));

        $resource = $this->generator->fromObject($instance, $this->request->reveal());

        $this->assertInstanceOf(HalResource::class, $resource);

        $self = $this->getLinkByRel('self', $resource);
        $this->assertLink('self', '/api/foo-bar/XXXX-YYYY-ZZZZ', $self);

        $this->assertEquals([
            'id'       => 'XXXX-YYYY-ZZZZ',
            'foo'      => 'BAR',
            'bar'      => 'BAZ',
            'children' => null,
        ], $resource->getElements());
    }

    public function testCanGenerateUrlBasedCollectionFromObjectDefinedInMetadataMap(): void
    {
        $first      = new TestAsset\FooBar();
        $first->id  = 'XXXX-YYYY-ZZZZ';
        $first->foo = 'BAR';
        $first->bar = 'BAZ';

        $second     = clone $first;
        $second->id = 'XXXX-YYYY-ZZZA';
        $third      = clone $first;
        $third->id  = 'XXXX-YYYY-ZZZB';

        $resourceMetadata = new Metadata\UrlBasedResourceMetadata(
            TestAsset\FooBar::class,
            '/api/foo/XXXX-YYYY-ZZZZ',
            self::getObjectPropertyHydratorClass()
        );

        $this->metadataMap->has(TestAsset\FooBar::class)->willReturn(true);
        $this->metadataMap->get(TestAsset\FooBar::class)->willReturn($resourceMetadata);

        $collectionMetadata = new Metadata\UrlBasedCollectionMetadata(
            ArrayIterator::class,
            'foo-bar',
            '/api/foo'
        );

        $this->metadataMap->has(ArrayIterator::class)->willReturn(true);
        $this->metadataMap->get(ArrayIterator::class)->willReturn($collectionMetadata);

        $collection = new ArrayIterator([$first, $second, $third]);

        $hydratorClass = self::getObjectPropertyHydratorClass();

        $this->hydrators->get($hydratorClass)->willReturn(new $hydratorClass());
        $this->linkGenerator->fromRoute()->shouldNotBeCalled();

        $resource = $this->generator->fromObject($collection, $this->request->reveal());

        $this->assertInstanceOf(HalResource::class, $resource);

        $self = $this->getLinkByRel('self', $resource);
        $this->assertLink('self', '/api/foo', $self);

        $this->assertEquals(3, $resource->getElement('_total_items'));

        $embedded = $resource->getElement('foo-bar');
        $this->assertInternalType('array', $embedded);
        $this->assertCount(3, $embedded);

        $ids = [];
        foreach ($embedded as $instance) {
            $this->assertInstanceOf(HalResource::class, $instance);
            $ids[] = $instance->getElement('id');

            $self = $this->getLinkByRel('self', $instance);
            $this->assertLink('self', '/api/foo/XXXX-YYYY-ZZZZ', $self);
        }

        $this->assertEquals([
            'XXXX-YYYY-ZZZZ',
            'XXXX-YYYY-ZZZA',
            'XXXX-YYYY-ZZZB',
        ], $ids);
    }

    public function testCanGenerateRouteBasedCollectionFromObjectDefinedInMetadataMap(): void
    {
        $instance      = new TestAsset\FooBar();
        $instance->foo = 'BAR';
        $instance->bar = 'BAZ';

        $resourceMetadata = new Metadata\RouteBasedResourceMetadata(
            TestAsset\FooBar::class,
            'foo-bar',
            self::getObjectPropertyHydratorClass(),
            'id',
            ['test' => 'param'],
            ['id' => 'foo_bar_id']
        );

        $this->metadataMap->has(TestAsset\FooBar::class)->willReturn(true);
        $this->metadataMap->get(TestAsset\FooBar::class)->willReturn($resourceMetadata);

        $instances = [];
        for ($i = 1; $i < 15; $i += 1) {
            $next        = clone $instance;
            $next->id    = $i;
            $instances[] = $next;

            $this->linkGenerator
                ->fromRoute(
                    'self',
                    $this->request->reveal(),
                    'foo-bar',
                    Argument::that(function (array $params) use ($i) {
                        return array_key_exists('foo_bar_id', $params)
                            && array_key_exists('test', $params)
                            && $params['foo_bar_id'] === $i
                            && $params['test'] === 'param';
                    })
                )
                ->willReturn(new Link('self', '/api/foo-bar/' . $i));
        }

        $collectionMetadata = new Metadata\RouteBasedCollectionMetadata(
            Paginator::class,
            'foo-bar',
            'foo-bar'
        );

        $this->metadataMap->has(Paginator::class)->willReturn(true);
        $this->metadataMap->get(Paginator::class)->willReturn($collectionMetadata);

        $this->linkGenerator
            ->fromRoute(
                'self',
                $this->request->reveal(),
                'foo-bar',
                Argument::type('array'),
                ['page' => 3]
            )
            ->willReturn(new Link('self', '/api/foo-bar?page=3'));
        $this->linkGenerator
            ->fromRoute(
                'first',
                $this->request->reveal(),
                'foo-bar',
                Argument::type('array'),
                ['page' => 1]
            )
            ->willReturn(new Link('first', '/api/foo-bar?page=1'));
        $this->linkGenerator
            ->fromRoute(
                'prev',
                $this->request->reveal(),
                'foo-bar',
                Argument::type('array'),
                ['page' => 2]
            )
            ->willReturn(new Link('prev', '/api/foo-bar?page=2'));
        $this->linkGenerator
            ->fromRoute(
                'next',
                $this->request->reveal(),
                'foo-bar',
                Argument::type('array'),
                ['page' => 4]
            )
            ->willReturn(new Link('next', '/api/foo-bar?page=4'));
        $this->linkGenerator
            ->fromRoute(
                'last',
                $this->request->reveal(),
                'foo-bar',
                Argument::type('array'),
                ['page' => 5]
            )
            ->willReturn(new Link('last', '/api/foo-bar?page=5'));

        $hydratorClass = self::getObjectPropertyHydratorClass();

        $this->hydrators->get($hydratorClass)->willReturn(new $hydratorClass());

        $this->request->getQueryParams()->willReturn(['page' => 3]);

        $collection = new Paginator(new ArrayAdapter($instances));
        $collection->setItemCountPerPage(3);

        $resource = $this->generator->fromObject($collection, $this->request->reveal());

        $this->assertInstanceOf(HalResource::class, $resource);

        $self = $this->getLinkByRel('self', $resource);
        $this->assertLink('self', '/api/foo-bar?page=3', $self);
        $first = $this->getLinkByRel('first', $resource);
        $this->assertLink('first', '/api/foo-bar?page=1', $first);
        $prev = $this->getLinkByRel('prev', $resource);
        $this->assertLink('prev', '/api/foo-bar?page=2', $prev);
        $next = $this->getLinkByRel('next', $resource);
        $this->assertLink('next', '/api/foo-bar?page=4', $next);
        $last = $this->getLinkByRel('last', $resource);
        $this->assertLink('last', '/api/foo-bar?page=5', $last);

        $this->assertEquals(14, $resource->getElement('_total_items'));
        $this->assertEquals(3, $resource->getElement('_page'));
        $this->assertEquals(5, $resource->getElement('_page_count'));

        $id = 7;
        foreach ($resource->getElement('foo-bar') as $item) {
            $self = $this->getLinkByRel('self', $item);
            $this->assertLink('self', '/api/foo-bar/' . $id, $self);

            $this->assertEquals($id, $item->getElement('id'));
            $id += 1;
        }
    }

    public function testGeneratedRouteBasedCollectionCastsPaginationMetadataToIntegers(): void
    {
        $instance      = new TestAsset\FooBar();
        $instance->foo = 'BAR';
        $instance->bar = 'BAZ';

        $resourceMetadata = new Metadata\RouteBasedResourceMetadata(
            TestAsset\FooBar::class,
            'foo-bar',
            self::getObjectPropertyHydratorClass(),
            'id',
            ['test' => 'param'],
            ['id' => 'foo_bar_id']
        );

        $this->metadataMap->has(TestAsset\FooBar::class)->willReturn(true);
        $this->metadataMap->get(TestAsset\FooBar::class)->willReturn($resourceMetadata);

        $instances = [];
        for ($i = 1; $i <= 5; $i += 1) {
            $next        = clone $instance;
            $next->id    = $i;
            $instances[] = $next;

            $this->linkGenerator
                ->fromRoute(
                    'self',
                    $this->request->reveal(),
                    'foo-bar',
                    Argument::that(function (array $params) use ($i) {
                        return array_key_exists('foo_bar_id', $params)
                            && array_key_exists('test', $params)
                            && $params['foo_bar_id'] === $i
                            && $params['test'] === 'param';
                    })
                )
                ->willReturn(new Link('self', '/api/foo-bar/' . $i));
        }

        $collectionMetadata = new Metadata\RouteBasedCollectionMetadata(
            Paginator::class,
            'foo-bar',
            'foo-bar'
        );

        $this->metadataMap->has(Paginator::class)->willReturn(true);
        $this->metadataMap->get(Paginator::class)->willReturn($collectionMetadata);

        $this->linkGenerator
            ->fromRoute(
                'self',
                $this->request->reveal(),
                'foo-bar',
                Argument::type('array'),
                ['page' => 3]
            )
            ->willReturn(new Link('self', '/api/foo-bar?page=3'));
        $this->linkGenerator
            ->fromRoute(
                'first',
                $this->request->reveal(),
                'foo-bar',
                Argument::type('array'),
                ['page' => 1]
            )
            ->willReturn(new Link('first', '/api/foo-bar?page=1'));
        $this->linkGenerator
            ->fromRoute(
                'prev',
                $this->request->reveal(),
                'foo-bar',
                Argument::type('array'),
                ['page' => 2]
            )
            ->willReturn(new Link('prev', '/api/foo-bar?page=2'));
        $this->linkGenerator
            ->fromRoute(
                'next',
                $this->request->reveal(),
                'foo-bar',
                Argument::type('array'),
                ['page' => 4]
            )
            ->willReturn(new Link('next', '/api/foo-bar?page=4'));
        $this->linkGenerator
            ->fromRoute(
                'last',
                $this->request->reveal(),
                'foo-bar',
                Argument::type('array'),
                ['page' => 5]
            )
            ->willReturn(new Link('last', '/api/foo-bar?page=5'));

        $hydratorClass = self::getObjectPropertyHydratorClass();

        $this->hydrators->get($hydratorClass)->willReturn(new $hydratorClass());

        $this->request->getQueryParams()->willReturn(['page' => '3']);

        $collection = new Paginator(new ArrayAdapter($instances));
        $collection->setItemCountPerPage(1);

        $resource = $this->generator->fromObject($collection, $this->request->reveal());

        $this->assertSame(5, $resource->getElement('_total_items'));
        $this->assertSame(3, $resource->getElement('_page'));
        $this->assertSame(5, $resource->getElement('_page_count'));
    }

    public function testGeneratorDoesNotAcceptPageQueryOutOfBounds(): void
    {
        $instance      = new TestAsset\FooBar();
        $instance->foo = 'BAR';
        $instance->bar = 'BAZ';

        $resourceMetadata = new Metadata\RouteBasedResourceMetadata(
            TestAsset\FooBar::class,
            'foo-bar',
            self::getObjectPropertyHydratorClass(),
            'id',
            ['test' => 'param'],
            ['id' => 'foo_bar_id']
        );

        $this->metadataMap->has(TestAsset\FooBar::class)->willReturn(true);
        $this->metadataMap->get(TestAsset\FooBar::class)->willReturn($resourceMetadata);

        $instances = [];
        for ($i = 1; $i < 15; $i += 1) {
            $next        = clone $instance;
            $next->id    = $i;
            $instances[] = $next;

            $this->linkGenerator
                ->fromRoute(
                    'self',
                    $this->request->reveal(),
                    'foo-bar',
                    [
                        'foo_bar_id' => $i,
                        'test'       => 'param',
                    ]
                )
                ->willReturn(new Link('self', '/api/foo-bar/' . $i));
        }

        $collectionMetadata = new Metadata\RouteBasedCollectionMetadata(
            Paginator::class,
            'foo-bar',
            'foo-bar'
        );

        $this->metadataMap->has(Paginator::class)->willReturn(true);
        $this->metadataMap->get(Paginator::class)->willReturn($collectionMetadata);

        $this->request->getQueryParams()->willReturn(['page' => 10]);

        $collection = new Paginator(new ArrayAdapter($instances));
        $collection->setItemCountPerPage(3);

        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('Page 10 is out of bounds. Collection has 5 pages.');
        $this->generator->fromObject($collection, $this->request->reveal());
    }

    public function testGeneratorDoesNotAcceptNegativePageQuery(): void
    {
        $instance      = new TestAsset\FooBar();
        $instance->foo = 'BAR';
        $instance->bar = 'BAZ';

        $resourceMetadata = new Metadata\RouteBasedResourceMetadata(
            TestAsset\FooBar::class,
            'foo-bar',
            self::getObjectPropertyHydratorClass(),
            'id',
            ['test' => 'param'],
            ['id' => 'foo_bar_id']
        );

        $this->metadataMap->has(TestAsset\FooBar::class)->willReturn(true);
        $this->metadataMap->get(TestAsset\FooBar::class)->willReturn($resourceMetadata);

        $instances = [];
        for ($i = 1; $i < 2; $i += 1) {
            $next        = clone $instance;
            $next->id    = $i;
            $instances[] = $next;

            $this->linkGenerator
                ->fromRoute(
                    'self',
                    $this->request->reveal(),
                    'foo-bar',
                    [
                        'foo_bar_id' => $i,
                        'test'       => 'param',
                    ]
                )
                ->willReturn(new Link('self', '/api/foo-bar/' . $i));
        }

        $collectionMetadata = new Metadata\RouteBasedCollectionMetadata(
            Paginator::class,
            'foo-bar',
            'foo-bar'
        );

        $this->metadataMap->has(Paginator::class)->willReturn(true);
        $this->metadataMap->get(Paginator::class)->willReturn($collectionMetadata);

        $this->request->getQueryParams()->willReturn(['page' => -10]);

        $collection = new Paginator(new ArrayAdapter($instances));
        $collection->setItemCountPerPage(3);

        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('Page -10 is out of bounds. Collection has 1 page.');
        $this->generator->fromObject($collection, $this->request->reveal());
    }

    public function testGeneratorAcceptsOnePageWhenCollectionHasNoEmbedded(): void
    {
        $instance      = new TestAsset\FooBar();
        $instance->foo = 'BAR';
        $instance->bar = 'BAZ';

        $resourceMetadata = new Metadata\RouteBasedResourceMetadata(
            TestAsset\FooBar::class,
            'foo-bar',
            self::getObjectPropertyHydratorClass(),
            'id',
            ['test' => 'param'],
            ['id' => 'foo_bar_id']
        );

        $this->metadataMap->has(TestAsset\FooBar::class)->willReturn(true);
        $this->metadataMap->get(TestAsset\FooBar::class)->willReturn($resourceMetadata);

        $this->request->getQueryParams()->willReturn([]);

        $this->linkGenerator
            ->fromRoute(
                'self',
                $this->request->reveal(),
                'foo-bar',
                [],
                ['page' => 1]
            )
            ->willReturn(new Link('self', '/api/foo-bar?page=3'));

        $instances = [];

        $collectionMetadata = new Metadata\RouteBasedCollectionMetadata(
            Paginator::class,
            'foo-bar',
            'foo-bar'
        );

        $this->metadataMap->has(Paginator::class)->willReturn(true);
        $this->metadataMap->get(Paginator::class)->willReturn($collectionMetadata);

        $collection = new Paginator(new ArrayAdapter($instances));
        $collection->setItemCountPerPage(3);

        $resource = $this->generator->fromObject($collection, $this->request->reveal());

        $this->assertEquals(0, $resource->getElement('_total_items'));
        $this->assertEquals(1, $resource->getElement('_page'));
        $this->assertEquals(0, $resource->getElement('_page_count'));
    }

    public function testGeneratorRaisesExceptionForUnknownObjectType(): void
    {
        $this->metadataMap->has(self::class)->willReturn(false);
        foreach (class_parents(self::class) as $parent) {
            $this->metadataMap->has($parent)->willReturn(false);
        }
        $this->expectException(InvalidObjectException::class);
        $this->expectExceptionMessage('not in metadata map');
        $this->generator->fromObject($this, $this->request->reveal());
    }

    /** @return iterable<string, array{0: ResourceGenerator\StrategyInterface, 1: class-string<Metadata\AbstractCollectionMetadata>}> */
    public function strategyCollection(): iterable
    {
        yield 'route-based-collection' => [
            new ResourceGenerator\RouteBasedCollectionStrategy(),
            Metadata\RouteBasedCollectionMetadata::class,
        ];

        yield 'url-based-collection' => [
            new ResourceGenerator\UrlBasedCollectionStrategy(),
            Metadata\UrlBasedCollectionMetadata::class,
        ];
    }

    /** @return iterable<string, array{0: ResourceGenerator\StrategyInterface}> */
    public function strategyResource(): iterable
    {
        yield 'route-based-resource' => [
            new ResourceGenerator\RouteBasedResourceStrategy(),
        ];

        yield 'url-based-resource' => [
            new ResourceGenerator\UrlBasedResourceStrategy(),
        ];
    }

    /**
     * @dataProvider strategyCollection
     * @dataProvider strategyResource
     */
    public function testUnexpectedMetadataForStrategy(ResourceGenerator\StrategyInterface $strategy): void
    {
        $this->generator->addStrategy(
            TestMetadata::class,
            $strategy
        );

        $collectionMetadata = new TestMetadata();

        $this->metadataMap->has(TestAsset\FooBar::class)->willReturn(true);
        $this->metadataMap->get(TestAsset\FooBar::class)->willReturn($collectionMetadata);

        $instance = new TestAsset\FooBar();

        $this->expectException(ResourceGenerator\Exception\UnexpectedMetadataTypeException::class);
        $this->expectExceptionMessage('Unexpected metadata of type');
        $this->generator->fromObject($instance, $this->request->reveal());
    }

    /**
     * @dataProvider strategyCollection
     * @param class-string<Metadata\AbstractCollectionMetadata> $metadata
     */
    public function testNotTraversableInstanceForCollectionStrategy(
        ResourceGenerator\StrategyInterface $strategy,
        string $metadata
    ): void {
        $collectionMetadata = new $metadata(
            TestAsset\FooBar::class,
            'foo-bar',
            '/api/foo'
        );

        $this->metadataMap->has(TestAsset\FooBar::class)->willReturn(true);
        $this->metadataMap->get(TestAsset\FooBar::class)->willReturn($collectionMetadata);

        $instance = new TestAsset\FooBar();

        $this->expectException(ResourceGenerator\Exception\InvalidCollectionException::class);
        $this->expectExceptionMessage('not a Traversable');
        $this->generator->fromObject($instance, $this->request->reveal());
    }

    public function testAddStrategyRaisesExceptionIfInvalidMetadataClass(): void
    {
        $this->expectException(UnknownMetadataTypeException::class);
        $this->expectExceptionMessage('does not exist, or does not extend');
        /** @psalm-suppress ArgumentTypeCoercion */
        $this->generator->addStrategy(stdClass::class, 'invalid-strategy');
    }

    public function testAddStrategyRaisesExceptionIfInvalidStrategyClass(): void
    {
        $this->expectException(InvalidStrategyException::class);
        $this->expectExceptionMessage('does not exist, or does not implement');
        /** @psalm-suppress ArgumentTypeCoercion */
        $this->generator->addStrategy(TestMetadata::class, 'invalid-strategy');
    }

    public function testPassesAllScalarEntityPropertiesAsRouteParametersWhenGeneratingUri(): void
    {
        $instance      = new TestAsset\FooBar();
        $instance->id  = 'XXXX-YYYY-ZZZZ';
        $instance->foo = 'BAR';
        $instance->bar = [
            'value' => 'baz',
        ];

        $metadata = new Metadata\RouteBasedResourceMetadata(
            TestAsset\FooBar::class,
            'foo-bar',
            self::getObjectPropertyHydratorClass(),
            'id',
            ['test' => 'param'],
            ['id' => 'foo_bar_id']
        );

        $this->metadataMap->has(TestAsset\FooBar::class)->willReturn(true);
        $this->metadataMap->get(TestAsset\FooBar::class)->willReturn($metadata);

        $hydratorClass = self::getObjectPropertyHydratorClass();

        $this->hydrators->get($hydratorClass)->willReturn(new $hydratorClass());
        $this->linkGenerator
            ->fromRoute(
                'self',
                $this->request->reveal(),
                'foo-bar',
                [
                    'foo_bar_id' => 'XXXX-YYYY-ZZZZ',
                    'foo'        => 'BAR',
                    'test'       => 'param',
                ]
            )
            ->willReturn(new Link('self', '/api/foo-bar/XXXX-YYYY-ZZZZ'));

        $resource = $this->generator->fromObject($instance, $this->request->reveal());

        $this->assertInstanceOf(HalResource::class, $resource);

        $self = $this->getLinkByRel('self', $resource);
        $this->assertLink('self', '/api/foo-bar/XXXX-YYYY-ZZZZ', $self);
    }

    public function testUsesConfiguredRoutePlaceholderMapToSpecifyRouteParams(): void
    {
        $instance      = new TestAsset\FooBar();
        $instance->id  = 'XXXX-YYYY-ZZZZ';
        $instance->foo = 'BAR';
        $instance->bar = 'BAZ';

        $metadata = new Metadata\RouteBasedResourceMetadata(
            TestAsset\FooBar::class,
            'foo-bar',
            self::getObjectPropertyHydratorClass(),
            'id',
            [],
            [
                'id'  => 'foo_bar_id',
                'foo' => 'foo_value',
                'bar' => 'bar_value',
            ]
        );

        $this->metadataMap->has(TestAsset\FooBar::class)->willReturn(true);
        $this->metadataMap->get(TestAsset\FooBar::class)->willReturn($metadata);

        $hydratorClass = self::getObjectPropertyHydratorClass();

        $this->hydrators->get($hydratorClass)->willReturn(new $hydratorClass());
        $this->linkGenerator
            ->fromRoute(
                'self',
                $this->request->reveal(),
                'foo-bar',
                [
                    'foo_bar_id' => 'XXXX-YYYY-ZZZZ',
                    'foo_value'  => 'BAR',
                    'bar_value'  => 'BAZ',
                ]
            )
            ->willReturn(new Link('self', '/api/foo-bar/XXXX-YYYY-ZZZZ/foo/BAR/bar/BAZ'));

        $resource = $this->generator->fromObject($instance, $this->request->reveal());

        $this->assertInstanceOf(HalResource::class, $resource);

        $self = $this->getLinkByRel('self', $resource);
        $this->assertLink('self', '/api/foo-bar/XXXX-YYYY-ZZZZ/foo/BAR/bar/BAZ', $self);
    }

    public function testParentClassesAreUsedWhenInstanceMetadataDoesNotExist(): void
    {
        $instance      = new TestAsset\InheritedClass();
        $instance->id  = 'XXXX-YYYY-ZZZZ';
        $instance->foo = 'BAR';
        $instance->bar = 'BAZ';

        $metadata = new Metadata\RouteBasedResourceMetadata(
            TestAsset\FooBar::class,
            'foo-bar',
            self::getObjectPropertyHydratorClass(),
            'id',
            [],
            [
                'id'  => 'foo_bar_id',
                'foo' => 'foo_value',
                'bar' => 'bar_value',
            ]
        );

        $this->metadataMap->has(TestAsset\InheritedClass::class)->willReturn(false);
        $this->metadataMap->has(TestAsset\InheritFooBar::class)->willReturn(false);
        $this->metadataMap->has(TestAsset\FooBar::class)->willReturn(true);
        $this->metadataMap->get(TestAsset\FooBar::class)->willReturn($metadata);

        $hydratorClass = self::getObjectPropertyHydratorClass();

        $this->hydrators->get($hydratorClass)->willReturn(new $hydratorClass());
        $this->linkGenerator
            ->fromRoute(
                'self',
                $this->request->reveal(),
                'foo-bar',
                [
                    'foo_bar_id' => 'XXXX-YYYY-ZZZZ',
                    'foo_value'  => 'BAR',
                    'bar_value'  => 'BAZ',
                ]
            )
            ->willReturn(new Link('self', '/api/foo-bar/XXXX-YYYY-ZZZZ/foo/BAR/bar/BAZ'));

        $resource = $this->generator->fromObject($instance, $this->request->reveal());

        $this->assertInstanceOf(HalResource::class, $resource);

        $self = $this->getLinkByRel('self', $resource);
        $this->assertLink('self', '/api/foo-bar/XXXX-YYYY-ZZZZ/foo/BAR/bar/BAZ', $self);
    }
}
