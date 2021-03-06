<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Migration\Step\UrlRewrite;

use Migration\App\ProgressBar;
use Migration\App\Step\RollbackInterface;
use Migration\ResourceModel\Destination;
use Migration\ResourceModel\Document;
use Migration\ResourceModel\Record;
use Migration\ResourceModel\RecordFactory;
use Migration\ResourceModel\Source;
use Migration\Reader\MapInterface;

/**
 * Class Version191to2000
 */
class Version191to2000 extends \Migration\Step\DatabaseStage implements RollbackInterface
{
    const SOURCE = 'core_url_rewrite';

    const DESTINATION = 'url_rewrite';
    const DESTINATION_PRODUCT_CATEGORY = 'catalog_url_rewrite_product_category';

    /**
     * @var string
     */
    protected $cmsPageTableName = 'cms_page';

    /**
     * @var string
     */
    protected $cmsPageStoreTableName = 'cms_page_store';

    /**
     * @var Source
     */
    protected $source;

    /**
     * @var Destination
     */
    protected $destination;

    /**
     * @var ProgressBar\LogLevelProcessor
     */
    protected $progress;

    /**
     * @var RecordFactory
     */
    protected $recordFactory;

    /**
     * @var \Migration\Logger\Logger
     */
    protected $logger;

    /**
     * @var string
     */
    protected $stage;

    /**
     * @var array
     */
    protected $redirectTypesMapping = [
        '' => 0,
        'R' => 302,
        'RP' => 301
    ];

    /**
     * Expected table structure
     * @var array
     */
    protected $structure = [
        MapInterface::TYPE_SOURCE => [
            'core_url_rewrite' => [
                'url_rewrite_id' ,
                'store_id',
                'id_path',
                'request_path',
                'target_path',
                'is_system',
                'options',
                'description',
                'category_id',
                'product_id',
            ],
        ],
        MapInterface::TYPE_DEST => [
            'url_rewrite' => [
                'url_rewrite_id',
                'entity_type',
                'entity_id',
                'request_path',
                'target_path',
                'redirect_type',
                'store_id',
                'description',
                'is_autogenerated',
                'metadata'
            ],
        ]
    ];

    /**
     * @param \Migration\Config $config
     * @param Source $source
     * @param Destination $destination
     * @param ProgressBar\LogLevelProcessor $progress
     * @param RecordFactory $factory
     * @param \Migration\Logger\Logger $logger
     * @param string $stage
     * @throws \Migration\Exception
     */
    public function __construct(
        \Migration\Config $config,
        Source $source,
        Destination $destination,
        ProgressBar\LogLevelProcessor $progress,
        RecordFactory $factory,
        \Migration\Logger\Logger $logger,
        $stage
    ) {
        parent::__construct($config);
        $this->source = $source;
        $this->destination = $destination;
        $this->progress = $progress;
        $this->recordFactory = $factory;
        $this->stage = $stage;
        $this->logger = $logger;
    }

    /**
     * Integrity check
     *
     * @return bool
     */
    protected function integrity()
    {
        $result = true;
        $this->progress->start(1);
        $this->progress->advance();
        $sourceFieldsDiff = array_diff(
            $this->structure[MapInterface::TYPE_SOURCE][self::SOURCE],
            array_keys($this->source->getStructure(self::SOURCE)->getFields())
        );
        $destinationFieldsDiff= array_diff(
            $this->structure[MapInterface::TYPE_DEST][self::DESTINATION],
            array_keys($this->destination->getStructure(self::DESTINATION)->getFields())
        );
        if ($sourceFieldsDiff) {
            $this->logger->error(sprintf(
                'Source fields are missing. Document: %s. Fields: %s',
                self::SOURCE,
                implode(',', $sourceFieldsDiff)
            ));
            $result = false;
        }
        if ($destinationFieldsDiff) {
            $this->logger->error(sprintf(
                'Destination fields are missing. Document: %s. Fields: %s',
                self::DESTINATION,
                implode(',', $destinationFieldsDiff)
            ));
            $result = false;
        }
        if ($result) {
            $this->progress->finish();
        }
        return (bool)$result;
    }

    /**
     * Run step
     *
     * @return bool
     */
    protected function data()
    {
        $this->progress->start(
            ceil($this->source->getRecordsCount(self::SOURCE) / $this->source->getPageSize(self::SOURCE))
        );

        $sourceDocument = $this->source->getDocument(self::SOURCE);
        $destDocument = $this->destination->getDocument(self::DESTINATION);
        $destProductCategory = $this->destination->getDocument(self::DESTINATION_PRODUCT_CATEGORY);

        $this->destination->clearDocument(self::DESTINATION);
        $this->destination->clearDocument(self::DESTINATION_PRODUCT_CATEGORY);

        $pageNumber = 0;
        while (!empty($bulk = $this->source->getRecords(self::SOURCE, $pageNumber))) {
            $pageNumber++;
            $destinationRecords = $destDocument->getRecords();
            $destProductCategoryRecords = $destProductCategory->getRecords();
            foreach ($bulk as $recordData) {
                /** @var Record $record */
                $record = $this->recordFactory->create(['document' => $sourceDocument, 'data' => $recordData]);
                /** @var Record $destRecord */
                $destRecord = $this->recordFactory->create(['document' => $destDocument]);
                $this->transform($record, $destRecord);
                if ($record->getValue('is_system')
                    && $record->getValue('product_id')
                    && $record->getValue('category_id')
                    && $record->getValue('request_path') !== null
                ) {
                    $destProductCategoryRecord = $this->recordFactory->create(['document' => $destProductCategory]);
                    $destProductCategoryRecord->setValue('url_rewrite_id', $record->getValue('url_rewrite_id'));
                    $destProductCategoryRecord->setValue('category_id', $record->getValue('category_id'));
                    $destProductCategoryRecord->setValue('product_id', $record->getValue('product_id'));
                    $destProductCategoryRecords->addRecord($destProductCategoryRecord);
                }

                $destinationRecords->addRecord($destRecord);
            }

            $this->progress->advance();
            $this->destination->saveRecords(self::DESTINATION, $destinationRecords);
            $this->destination->saveRecords(self::DESTINATION_PRODUCT_CATEGORY, $destProductCategoryRecords);

        }
        $this->saveCmsPageRewrites();
        $this->progress->finish();
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function perform()
    {
        if (!method_exists($this, $this->stage)) {
            throw new \Migration\Exception('Invalid step configuration');
        }

        return call_user_func([$this, $this->stage]);
    }

    /**
     * Volume check
     *
     * @return bool
     */
    protected function volume()
    {
        $result = true;
        $this->progress->start(1);
        $result &= $this->source->getRecordsCount(self::SOURCE) + $this->countCmsPageRewrites() ==
            ($this->destination->getRecordsCount(self::DESTINATION));
        if (!$result) {
            $this->logger->error('Mismatch of entities in the document: url_rewrite');
        }
        $this->progress->advance();
        $this->progress->finish();
        return (bool)$result;
    }

    /**
     * Get request_paths from core_url_rewrite that matches cms_page.identifier
     *
     * @return \Magento\Framework\Db\Select
     */
    protected function getUrlRewriteRequestPathsSelect()
    {
        $select = $this->source->getAdapter()->getSelect();
        $select->from(
            ['cur' => $this->source->addDocumentPrefix(self::SOURCE)],
            ['cur.request_path']
        )->joinLeft(
            ['cp' => $this->source->addDocumentPrefix($this->cmsPageTableName)],
            'cur.request_path = cp.identifier',
            []
        );

        return $select;
    }

    /**
     * @inheritdoc
     */
    public function rollback()
    {
        return true;
    }

    /**
     * Record transformer
     *
     * @param Record $record
     * @param Record $destRecord
     * @return void
     */
    private function transform(Record $record, Record $destRecord)
    {
        $destRecord->setValue('url_rewrite_id', $record->getValue('url_rewrite_id'));
        $destRecord->setValue('store_id', $record->getValue('store_id'));
        $destRecord->setValue('description', $record->getValue('description'));

        $destRecord->setValue('request_path', $record->getValue('request_path'));
        $destRecord->setValue('target_path', $record->getValue('target_path'));
        $destRecord->setValue('is_autogenerated', $record->getValue('is_system'));

        $destRecord->setValue('entity_type', $this->getRecordEntityType($record));

        $metadata = $this->doRecordSerialization($record)
            ? serialize(['category_id' => $record->getValue('category_id')])
            : null ;
        $destRecord->setValue('metadata', $metadata);

        $destRecord->setValue('entity_id', $record->getValue('product_id') ?: $record->getValue('category_id'));
        $redirectType = isset($this->redirectTypesMapping[$record->getValue('options')])
            ? $this->redirectTypesMapping[$record->getValue('options')]
            : $this->redirectTypesMapping[''];
        $destRecord->setValue('redirect_type', $redirectType);
    }

    /**
     * @param Record $record
     * @return bool
     */
    private function doRecordSerialization(Record $record)
    {
        return $record->getValue('is_system') && $record->getValue('product_id') && $record->getValue('category_id');
    }

    /**
     * @param Record $record
     * @return mixed
     */
    public function getRecordEntityType(Record $record)
    {
        $isCategory = $record->getValue('category_id') ? 'category' : null;
        $isProduct = $record->getValue('product_id') ? 'product' : null;
        return $isProduct ?: $isCategory;
    }

    /**
     * @return \Magento\Framework\Db\Select
     */
    protected function selectCmsPageRewrites()
    {
        $this->progress->advance();
        /** @var \Magento\Framework\Db\Select $select */
        $select = $this->source->getAdapter()->getSelect();
        $select->distinct()->from(
            ['cp' => $this->source->addDocumentPrefix($this->cmsPageTableName)],
            [
                new \Zend_Db_Expr('"cms-page" as `entity_type`'),
                'entity_id' => 'cp.page_id',
                'request_path' => 'cp.identifier',
                'target_path' => 'CONCAT("cms/page/view/page_id/", cp.page_id)',
                'store_id' => 'IF(cps.store_id = 0, 1, cps.store_id)',
                new \Zend_Db_Expr('1 as `is_autogenerated`')
            ]
        )->joinLeft(
            ['cps' => $this->source->addDocumentPrefix($this->cmsPageStoreTableName)],
            'cps.page_id = cp.page_id',
            []
        )->where(
            'cp.is_active = 1'
        )->where(
            'cp.identifier NOT IN(?)',
            $this->getUrlRewriteRequestPathsSelect()
        )->group(['request_path', 'cps.store_id']);

        return $select;
    }

    /**
     * @return void
     */
    protected function saveCmsPageRewrites()
    {
        $select = $this->selectCmsPageRewrites();
        $urlRewrites = $this->source->getAdapter()->loadDataFromSelect($select);
        $this->destination->saveRecords(self::DESTINATION, $urlRewrites, ['request_path' => 'request_path']);
    }

    /**
     * @return int
     */
    protected function countCmsPageRewrites()
    {
        $select = $this->selectCmsPageRewrites();
        $urlRewrites = $this->source->getAdapter()->loadDataFromSelect($select);
        return count($urlRewrites);
    }
}
