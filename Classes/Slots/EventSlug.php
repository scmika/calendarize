<?php

/**
 * Event search service.
 */
declare(strict_types=1);

namespace HDNET\Calendarize\Slots;

use DateTime;
use HDNET\Autoloader\Annotation\SignalClass;
use HDNET\Autoloader\Annotation\SignalName;
use HDNET\Calendarize\Domain\Model\Event;
use HDNET\Calendarize\Domain\Repository\EventRepository;
use HDNET\Calendarize\Register;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Event search service.
 */
class EventSlug
{
    /**
     * Check if we can reduce the amount of results.
     *
     * @SignalClass(value="HDNET\Calendarize\Service\IndexPreparationService")
     * @SignalName(value="makeSlug")
     *
     * @param string         $configurationKey
     * @param int            $uid
     * @param DateTime|null $startDate
     * @param int            $startTime
     * @param DateTime|null $endDate
     * @param int            $endTime
     * @param string         $slug
     *
     * @return array
     */
    public function makeSlug(
        string $configurationKey,
        int $uid,
        ?DateTime $startDate = null,
        int $startTime = 0,
        ?DateTime $endDate = null,
        int $endTime = 0,
        string $slug = ''
    ) {
        if ($this->getUniqueRegisterKey() !== $configurationKey) {
            return null;
        }

        if (isset($slug) && ($slug !== '')) {
            return null;
        }

        /** @var EventRepository $eventRepository */
        $eventRepository = GeneralUtility::makeInstance(EventRepository::class);
        /** @var Event $event */
        $event = $eventRepository->findByUid($uid);
        $slug = $this->slugify($event->getTitle().'-'.$startDate->format('Y-m-d'));

        return [
            'configurationKey' => $configurationKey,
            'uid' => $uid,
            'startDate' => $startDate,
            'startTime' => $startTime,
            'endDate' => $endDate,
            'endTime' => $endTime,
            'slug' => $slug,
        ];
    }

    /**
     * Takes a string and removes everything not lowercase a-z, 0-9 and -
     *
     * @param string $slugPart
     * @return string
     */
    protected function slugify(string $slugPart = '')
    {
        // @todo: Convert european extra signs like ö ä ü or cyrillic signs like яблоко
        $slugPart = preg_replace('/[^a-z0-9-]/', '-', strtolower($slugPart));
        // Replace -- with -
        $slugPart = preg_replace('/--/', '-', $slugPart);
        return $slugPart;
    }

    /**
     * Unique register key.
     *
     * @return string
     */
    protected function getUniqueRegisterKey()
    {
        $config = Register::getDefaultCalendarizeConfiguration();

        return $config['uniqueRegisterKey'];
    }
}
