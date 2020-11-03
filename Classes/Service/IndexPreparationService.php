<?php

/**
 * Helper class for the IndexService
 * Prepare the index.
 */
declare(strict_types=1);

namespace HDNET\Calendarize\Service;

use HDNET\Calendarize\Domain\Model\Index;
use HDNET\Calendarize\Domain\Repository\IndexRepository;
use HDNET\Calendarize\Register;
use HDNET\Calendarize\Utility\HelperUtility;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\SignalSlot\Dispatcher;

/**
 * Helper class for the IndexService
 * Prepare the index.
 */
class IndexPreparationService
{
    /**
     * Build the index for one element.
     *
     * @param string $configurationKey
     * @param string $tableName
     * @param int    $uid
     *
     * @return array
     */
    public function prepareIndex($configurationKey, $tableName, $uid)
    {
        $rawRecord = BackendUtility::getRecord($tableName, $uid);
        if (!$rawRecord) {
            return [];
        }

        $register = Register::getRegister();
        $fieldName = isset($register[$configurationKey]['fieldName']) ? $register[$configurationKey]['fieldName'] : 'calendarize';
        $configurations = GeneralUtility::intExplode(',', $rawRecord[$fieldName], true);

        $transPointer = $GLOBALS['TCA'][$tableName]['ctrl']['transOrigPointerField'] ?? false; // e.g. l10n_parent
        if ($transPointer && (int)$rawRecord[$transPointer] > 0) {
            $rawOriginalRecord = BackendUtility::getRecord($tableName, (int)$rawRecord[$transPointer]);
            $configurations = GeneralUtility::intExplode(',', $rawOriginalRecord[$fieldName], true);
        }

        $neededItems = [];
        if ($configurations) {
            $timeTableService = GeneralUtility::makeInstance(TimeTableService::class);
            $neededItems = $timeTableService->getTimeTablesByConfigurationIds($configurations);
            $indexRepository = GeneralUtility::makeInstance(IndexRepository::class);
            $signalSlot = GeneralUtility::makeInstance(Dispatcher::class);
            foreach ($neededItems as $key => $record) {
                $record['foreign_table'] = $tableName;
                $record['foreign_uid'] = $uid;
                $record['unique_register_key'] = $configurationKey;

                // UTC fix
                $record['start_date'] = \DateTime::createFromFormat(
                    'Y-m-d H:i:s',
                    $record['start_date']->format('Y-m-d') . ' 00:00:00',
                    new \DateTimeZone('UTC')
                );
                $record['end_date'] = \DateTime::createFromFormat(
                    'Y-m-d H:i:s',
                    $record['end_date']->format('Y-m-d') . ' 00:00:00',
                    new \DateTimeZone('UTC')
                );
                $slug = '';
                $slugParams = [
                    'configurationKey' => $configurationKey,
                    'uid' => $uid,
                    'startDate' => $record['start_date'],
                    'startTime' => $record['start_time'],
                    'endDate' => $record['end_date'],
                    'endTime' => $record['end_time'],
                    'slug' => $slug,
                ];
                $returnedParams = $signalSlot->dispatch(__CLASS__, 'makeSlug', $slugParams);
                $record['slug'] = $returnedParams['slug'];
                if (!isset($record['slug']) || !$record['slug']) {
                    $record['slug'] = strtolower($configurationKey).'-'.$record['foreign_uid'].'-'.$record['start_date']->format('Y-m-d');
                }
                $record['slug'] = $this->makeUniqueSlug($indexRepository, $record['slug'], $configurationKey, $tableName, $uid, $record['start_date'], $record['start_time']);
                $this->prepareRecordForDatabase($record);
                $neededItems[$key] = $record;
            }
        }

        $this->addEnableFieldInformation($neededItems, $tableName, $rawRecord);
        $this->addLanguageInformation($neededItems, $tableName, $rawRecord);

        return $neededItems;
    }

    /**
     * This function returns a unique new slug or a previous one, if already used for same exact combination
     *
     * @param IndexRepository $indexRepository
     * @param string $slug
     * @param string $expectedConfigurationKey
     * @param string $expectedTable
     * @param int $expectedUid
     * @param \DateTime $expectedStartDate
     * @param int $expectedStartTime
     *
     * @return string
     */
    protected function makeUniqueSlug($indexRepository, $slug, $expectedConfigurationKey, $expectedTable, $expectedUid, $expectedStartDate, $expectedStartTime) {
        $slugResults = $indexRepository->findBySlugLike($slug);
        $slugCounter = 0;
        foreach ($slugResults as $slugResult) {
            if ($this->isSlugEqual($slugResult, $slugCounter, $slug, $expectedConfigurationKey, $expectedTable, $expectedUid, $expectedStartDate, $expectedStartTime)) {
                return $slugResult->getSlug();
            }
            // No match => increase counter
            ++$slugCounter;
        }
        if ($slugCounter > 0) {
            return $slug.'-'.$slugCounter;
        } else {
            return $slug;
        }
    }

    /**
     * Only if all components match, treat this as the expected and correct slug.
     *
     * @param Index $slugResult Index object
     * @param int $slugCounter Current counter, increased if numbers are skipped
     * @param string $slug real_url equivalent shorthand
     * @param string $expectedConfigurationKey Configuration key, which must match the Index data
     * @param string $expectedTable Foreign table which must match the Index data
     * @param int $expectedUid Foreign uid which must match the Index data
     * @param \DateTime $expectedStartDate Start date which must match the Index data
     * @param int $expectedStartTime Start time which must match the Index data
     *
     * @return bool True if all relevant parameters are equal, false otherwise
     */
    protected function isSlugEqual(Index $slugResult, &$slugCounter, $slug, $expectedConfigurationKey, $expectedTable, $expectedUid, $expectedStartDate, $expectedStartTime) {
        // Check last block (= unique number part)
        $remainingSlug = substr($slugResult->getSlug(), strlen($slug)+1);
        if ($remainingSlug) {
            $slugComponents = explode('-', $remainingSlug);
            if (count($slugComponents) === 2) {
                $currentSlugNumber = (int)$slugComponents[1];
                if ($currentSlugNumber > $slugCounter) {
                    // If any number was skipped, use the highest number for subsequent checks
                    $slugCounter = $currentSlugNumber;
                }
            }
        }
        if ($slugResult->getUniqueRegisterKey() !== $expectedConfigurationKey) {
            return false;
        }
        if ($slugResult->getForeignTable() !== $expectedTable) {
            return false;
        }
        if ($slugResult->getForeignUid() !== (int)$expectedUid) {
            return false;
        }
        if ($slugResult->getStartDate()->getTimestamp() !== $expectedStartDate->getTimestamp()) {
            return false;
        }
        if ((int)$slugResult->getStartTime() !== (int)$expectedStartTime) {
            return false;
        }
        return true;
    }

    /**
     * Add the language information.
     *
     * @param array  $neededItems
     * @param string $tableName
     * @param array  $record
     */
    protected function addLanguageInformation(array &$neededItems, $tableName, array $record)
    {
        $languageField = $GLOBALS['TCA'][$tableName]['ctrl']['languageField'] ?? false; // e.g. sys_language_uid
        $transPointer = $GLOBALS['TCA'][$tableName]['ctrl']['transOrigPointerField'] ?? false; // e.g. l10n_parent

        if ($transPointer && (int)$record[$transPointer] > 0) {
            foreach ($neededItems as $key => $value) {
                $originalRecord = BackendUtility::getRecord($value['foreign_table'], $value['foreign_uid']);

                $searchFor = $value;
                $searchFor['foreign_uid'] = (int)$originalRecord[$transPointer];

                $db = HelperUtility::getDatabaseConnection(IndexerService::TABLE_NAME);
                $q = $db->createQueryBuilder();
                $where = [];
                foreach ($searchFor as $field => $val) {
                    if (\is_string($val)) {
                        $where[] = $q->expr()->eq($field, $q->quote($val));
                    } else {
                        $where[] = $q->expr()->eq($field, (int)$val);
                    }
                }

                $result = $q->select('uid')->from(IndexerService::TABLE_NAME)->andWhere(...$where)->execute()->fetch();
                if (isset($result['uid'])) {
                    $neededItems[$key]['l10n_parent'] = (int)$result['uid'];
                }
            }
        }

        if ($languageField && 0 !== (int)$record[$languageField]) {
            $language = (int)$record[$languageField];
            foreach (\array_keys($neededItems) as $key) {
                $neededItems[$key]['sys_language_uid'] = $language;
            }
        }
    }

    /**
     * Add the enable field information.
     *
     * @param array  $neededItems
     * @param string $tableName
     * @param array  $record
     */
    protected function addEnableFieldInformation(array &$neededItems, $tableName, array $record)
    {
        $enableFields = $GLOBALS['TCA'][$tableName]['ctrl']['enablecolumns'] ?? [];
        if (!$enableFields) {
            return;
        }

        $addFields = [];
        if (isset($enableFields['disabled'])) {
            $addFields['hidden'] = (int)$record[$enableFields['disabled']];
        }
        if (isset($enableFields['starttime'])) {
            $addFields['starttime'] = (int)$record[$enableFields['starttime']];
        }
        if (isset($enableFields['endtime'])) {
            $addFields['endtime'] = (int)$record[$enableFields['endtime']];
        }
        if (isset($enableFields['fe_group'])) {
            $addFields['fe_group'] = (string)$record[$enableFields['fe_group']];
        }

        foreach ($neededItems as $key => $value) {
            $neededItems[$key] = \array_merge($value, $addFields);
        }
    }

    /**
     * Prepare the record for the database insert.
     *
     * @param $record
     */
    protected function prepareRecordForDatabase(&$record)
    {
        foreach ($record as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $record[$key] = $value->format('Y-m-d');
            } elseif (\is_bool($value) || 'start_time' === $key || 'end_time' === $key) {
                $record[$key] = (int)$value;
            } elseif (null === $value) {
                $record[$key] = '';
            }
        }
    }
}
