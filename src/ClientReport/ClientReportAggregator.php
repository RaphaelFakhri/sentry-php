<?php

declare(strict_types=1);

namespace Sentry\ClientReport;

use Sentry\Attributes\AttributeBag;
use Sentry\Event;
use Sentry\EventType;
use Sentry\Logs\Log;
use Sentry\Metrics\Types\Metric;
use Sentry\State\HubAdapter;
use Sentry\Transport\DataCategory;

class ClientReportAggregator
{
    /**
     * @var self
     */
    private static $instance;

    /**
     * Nested array for local aggregation. The first key is the category and the second one is the reason.
     *
     * ```
     * [
     *  'example-category' => [
     *      'example-reason' => 10
     *   ]
     * ]
     *```
     *
     * @var array<array<string, int>>
     */
    private $reports = [];

    public function add(DataCategory $category, Reason $reason, int $quantity): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $category = $category->getValue();
        $reason = $reason->getValue();
        if ($quantity <= 0) {
            $client = HubAdapter::getInstance()->getClient();
            if ($client !== null) {
                $logger = $client->getOptions()->getLoggerOrNullLogger();
                $logger->debug('Dropping Client report with category={category} and reason={reason} because quantity is zero or negative ({quantity})', [
                    'category' => $category,
                    'reason' => $reason,
                    'quantity' => $quantity,
                ]);
            }

            return;
        }
        $this->reports[$category][$reason] = ($this->reports[$category][$reason] ?? 0) + $quantity;
    }

    /**
     * Records a discarded event in all the data categories it accounts for, e.g. a
     * transaction also discards its spans and its profile.
     */
    public function addEvent(Event $event, Reason $reason): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        switch ($event->getType()) {
            case EventType::event():
                $this->add(DataCategory::error(), $reason, 1);
                $this->addAttachments($event, $reason);
                break;
            case EventType::transaction():
                $this->add(DataCategory::transaction(), $reason, 1);
                // Relay extracts an additional span from the transaction itself
                $this->add(DataCategory::span(), $reason, \count($event->getSpans()) + 1);

                if ($event->getSdkMetadata('profile') !== null) {
                    $this->add(DataCategory::profile(), $reason, 1);
                }

                $this->addAttachments($event, $reason);
                break;
            case EventType::checkIn():
                $this->add(DataCategory::checkIn(), $reason, 1);
                break;
            case EventType::logs():
                $logs = $event->getLogs();
                $this->add(DataCategory::logItem(), $reason, \count($logs));
                $this->add(DataCategory::logBytes(), $reason, array_sum(array_map(static function (Log $log): int {
                    return self::getLogSize($log);
                }, $logs)));
                break;
            case EventType::metrics():
                $metrics = $event->getMetrics();
                $this->add(DataCategory::metric(), $reason, \count($metrics));
                $this->add(DataCategory::metricBytes(), $reason, array_sum(array_map(static function (Metric $metric): int {
                    return self::getMetricSize($metric);
                }, $metrics)));
                break;
        }
    }

    public function flush(): void
    {
        if (empty($this->reports)) {
            return;
        }
        $reports = [];
        foreach ($this->reports as $category => $reasons) {
            foreach ($reasons as $reason => $quantity) {
                $reports[] = new DiscardedEvent($category, $reason, $quantity);
            }
        }
        $event = Event::createClientReport();
        $event->setClientReports($reports);

        $client = HubAdapter::getInstance()->getClient();

        // Reset the client reports only if we successfully sent an event. If it fails it
        // can be sent on the next flush, or it gets discarded anyway.
        if ($client !== null && $client->captureEvent($event) !== null) {
            $this->reports = [];
        }
    }

    private function isEnabled(): bool
    {
        $client = HubAdapter::getInstance()->getClient();

        return $client !== null && $client->getOptions()->shouldSendClientReports();
    }

    private function addAttachments(Event $event, Reason $reason): void
    {
        $bytes = 0;

        foreach ($event->getAttachments() as $attachment) {
            $bytes += $attachment->getSize() ?? 0;
        }

        if ($bytes > 0) {
            $this->add(DataCategory::attachment(), $reason, $bytes);
        }
    }

    private static function getLogSize(Log $log): int
    {
        return max(1, \strlen($log->getBody()) + self::getAttributesSize($log->attributes()));
    }

    private static function getMetricSize(Metric $metric): int
    {
        return max(1, \strlen($metric->getName()) + self::getValueSize($metric->getValue()) + self::getAttributesSize($metric->getAttributes()));
    }

    private static function getAttributesSize(AttributeBag $attributes): int
    {
        $size = 0;

        foreach ($attributes->all() as $key => $attribute) {
            $size += \strlen($key) + self::getValueSize($attribute->getValue());
        }

        return $size;
    }

    /**
     * @param string|bool|int|float|null $value
     */
    private static function getValueSize($value): int
    {
        if (\is_string($value)) {
            return \strlen($value);
        }

        if (\is_bool($value)) {
            return 1;
        }

        if ($value === null) {
            return 0;
        }

        return 8;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }
}
