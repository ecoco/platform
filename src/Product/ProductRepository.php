<?php
/**
 * Shopware 5
 * Copyright (c) shopware AG
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License with an additional
 * permission and of our proprietary license can be found at and
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "Shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, title and interest in
 * our trademarks remain entirely with us.
 */

namespace Shopware\Product;

use Shopware\Context\Struct\TranslationContext;
use Shopware\Framework\Write\FieldAware\DefaultExtender;
use Shopware\Framework\Write\FieldAware\FieldExtenderCollection;
use Shopware\Framework\Write\FieldException\MalformatDataException;
use Shopware\Framework\Write\FieldException\WriteStackException;
use Shopware\Framework\Write\WriteContext;
use Shopware\Framework\Write\Writer;
use Shopware\Product\Event\ProductBasicLoadedEvent;
use Shopware\Product\Event\ProductCreatedEvent;
use Shopware\Product\Event\ProductDetailLoadedEvent;
use Shopware\Product\Event\ProductUpdatedEvent;
use Shopware\Product\Event\ProductWriteExtenderEvent;
use Shopware\Product\Exception\MalformatInputException;
use Shopware\Product\Loader\ProductBasicLoader;
use Shopware\Product\Loader\ProductDetailLoader;
use Shopware\Product\Searcher\ProductSearcher;
use Shopware\Product\Struct\ProductBasicCollection;
use Shopware\Product\Struct\ProductDetailCollection;
use Shopware\Product\Struct\ProductSearchResult;
use Shopware\Search\AggregationResult;
use Shopware\Search\Criteria;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class ProductRepository
{
    /**
     * @var ProductDetailLoader
     */
    protected $detailLoader;

    /**
     * @var ProductBasicLoader
     */
    private $basicLoader;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var ProductSearcher
     */
    private $searcher;

    /**
     * @var Writer
     */
    private $writer;

    /**
     * @var DefaultExtender
     */
    private $extender;

    public function __construct(
        ProductBasicLoader $basicLoader,
        EventDispatcherInterface $eventDispatcher,
        ProductSearcher $searcher,
        ProductDetailLoader $detailLoader,
        Writer $writer,
        DefaultExtender $extender
    ) {
        $this->basicLoader = $basicLoader;
        $this->eventDispatcher = $eventDispatcher;
        $this->searcher = $searcher;
        $this->writer = $writer;
        $this->detailLoader = $detailLoader;
        $this->extender = $extender;
    }

    public function readDetail(array $uuids, TranslationContext $context): ProductDetailCollection
    {
        $collection = $this->detailLoader->load($uuids, $context);

        $this->eventDispatcher->dispatch(
            ProductDetailLoadedEvent::NAME,
            new ProductDetailLoadedEvent($collection, $context)
        );

        return $collection;
    }

    public function read(array $uuids, TranslationContext $context): ProductBasicCollection
    {
        $collection = $this->basicLoader->load($uuids, $context);

        $this->eventDispatcher->dispatch(
            ProductBasicLoadedEvent::NAME,
            new ProductBasicLoadedEvent($collection, $context)
        );

        return $collection;
    }

    public function search(Criteria $criteria, TranslationContext $context): ProductSearchResult
    {
        /** @var ProductSearchResult $result */
        $result = $this->searcher->search($criteria, $context);

        $this->eventDispatcher->dispatch(
            ProductBasicLoadedEvent::NAME,
            new ProductBasicLoadedEvent($result, $context)
        );

        return $result;
    }

    public function aggregate(Criteria $criteria, TranslationContext $context): AggregationResult
    {
        $result = $this->searcher->aggregate($criteria, $context);

        return $result;
    }

    public function update(array $data, TranslationContext $context): ProductUpdatedEvent
    {
        $writeContext = $this->createWriteContext($context->getShopUuid());
        $extender = $this->getExtender();

        $this->validateWriteInput($data);

        $updated = $errors = [];

        foreach ($data as $product) {
            try {
                $updated[] = $this->writer->update(\Shopware\Product\Writer\ProductResource::class, $product, $writeContext, $extender);
            } catch (WriteStackException $exception) {
                $errors[] = $exception->toArray();
            }
        }

        $event = new ProductUpdatedEvent(array_column($updated, 'uuid'), $errors);

        return $this->eventDispatcher->dispatch(ProductUpdatedEvent::EVENT_NAME, $event);
    }

    public function create(array $data, TranslationContext $context): ProductCreatedEvent
    {
        $writeContext = $this->createWriteContext($context->getShopUuid());
        $extender = $this->getExtender();

        $this->validateWriteInput($data);

        $created = $errors = [];

        foreach ($data as $product) {
            try {
                $created[] = $this->writer->insert(\Shopware\Product\Writer\ProductResource::class, $product, $writeContext, $extender);
            } catch (WriteStackException $exception) {
                $errors[] = $exception->toArray();
            }
        }

        $event = new ProductCreatedEvent(array_column($created, 'uuid'), $errors);

        return $this->eventDispatcher->dispatch(ProductCreatedEvent::EVENT_NAME, $event);
    }

    private function createWriteContext(string $shopUuid): WriteContext
    {
        $writeContext = new WriteContext();
        $writeContext->set(\Shopware\Shop\Writer\ShopResource::class, 'uuid', $shopUuid);

        return $writeContext;
    }

    private function getExtender(): FieldExtenderCollection
    {
        $extenderCollection = new FieldExtenderCollection();
        $extenderCollection->addExtender($this->extender);

        $event = $this->eventDispatcher->dispatch(ProductWriteExtenderEvent::EVENT_NAME, new ProductWriteExtenderEvent($extenderCollection));

        return $event->getExtenderCollection();
    }

    private function validateWriteInput(array $data): void
    {
        $malformedRows = [];

        foreach ($data as $index => $row) {
            if (!is_array($row)) {
                $malformedRows[] = $index;
            }
        }

        if (0 === count($malformedRows)) {
            return;
        }

        throw new \InvalidArgumentException('Expected input to be array.');
    }
}
