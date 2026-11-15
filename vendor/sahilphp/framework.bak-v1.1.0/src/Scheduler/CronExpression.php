<?php

declare(strict_types=1);

namespace Sahil\Scheduler;

use DateTimeImmutable;

/**
 * A five-field cron expression.
 *
 * Supported syntax, per field:
 *  - `*`                 every value
 *  - `5`                 a single value
 *  - `1-10`              a range
 *  - `1,5,20`            a list
 *  - `*` plus `/5`       a step (every five units)
 *  - `1-10` plus `/2`    a stepped range
 *  - `JAN` … `DEC`       month names (months field)
 *  - `SUN` … `SAT`       weekday names (weekdays field; 0 and 7 are Sunday)
 *
 * Matching follows the standard cron day rule: when BOTH day-of-month and
 * day-of-week are restricted, a day matches if EITHER matches; when one of
 * them is `*`, the other decides. Matching happens on the wall-clock minute
 * in the timezone of the given instant, so daylight-saving transitions are
 * honoured by construction: a local time that does not exist (spring
 * forward) simply never matches, and a local time that occurs twice (fall
 * back) matches both occurrences.
 */
final class CronExpression
{
    private const MONTHS = [
        'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
        'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
    ];

    private const WEEKDAYS = [
        'SUN' => 0, 'MON' => 1, 'TUE' => 2, 'WED' => 3,
        'THU' => 4, 'FRI' => 5, 'SAT' => 6,
    ];

    /**
     * @param array<int, true> $minutes
     * @param array<int, true> $hours
     * @param array<int, true> $daysOfMonth
     * @param array<int, true> $months
     * @param array<int, true> $daysOfWeek
     */
    private function __construct(
        private readonly string $expression,
        private readonly array $minutes,
        private readonly array $hours,
        private readonly array $daysOfMonth,
        private readonly array $months,
        private readonly array $daysOfWeek,
        private readonly bool $anyDayOfMonth,
        private readonly bool $anyDayOfWeek,
    ) {
    }

    /**
     * Parse an expression, throwing on anything malformed.
     *
     * @throws SchedulerException
     */
    public static function parse(string $expression): self
    {
        $fields = preg_split('/\s+/', trim($expression));

        if ($fields === false || count($fields) !== 5) {
            throw new SchedulerException(sprintf(
                'Cron expression [%s] must have exactly five fields (minute hour day-of-month month day-of-week).',
                $expression,
            ));
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $fields;

        $minutes = self::parseField($minute, 0, 59, [], 'minute');
        $hours = self::parseField($hour, 0, 23, [], 'hour');
        $daysOfMonth = self::parseField($dayOfMonth, 1, 31, [], 'day-of-month');
        $months = self::parseField($month, 1, 12, self::MONTHS, 'month');
        $daysOfWeek = self::parseField($dayOfWeek, 0, 7, self::WEEKDAYS, 'day-of-week');

        // 7 is an alias for Sunday in the weekdays field.
        if (isset($daysOfWeek[7])) {
            unset($daysOfWeek[7]);
            $daysOfWeek[0] = true;
        }

        ksort($daysOfWeek);

        return new self(
            $expression,
            $minutes,
            $hours,
            $daysOfMonth,
            $months,
            $daysOfWeek,
            trim($dayOfMonth) === '*',
            trim($dayOfWeek) === '*',
        );
    }

    public function expression(): string
    {
        return $this->expression;
    }

    /**
     * Whether the expression matches the minute of the given instant.
     */
    public function isDue(DateTimeImmutable $at): bool
    {
        if (!isset($this->minutes[(int) $at->format('i')])) {
            return false;
        }

        if (!isset($this->hours[(int) $at->format('G')])) {
            return false;
        }

        if (!isset($this->months[(int) $at->format('n')])) {
            return false;
        }

        $dayOfMonth = isset($this->daysOfMonth[(int) $at->format('j')]);
        $dayOfWeek = isset($this->daysOfWeek[(int) $at->format('w')]);

        if ($this->anyDayOfMonth || $this->anyDayOfWeek) {
            return $dayOfMonth && $dayOfWeek;
        }

        return $dayOfMonth || $dayOfWeek;
    }

    /**
     * @param array<string, int> $names
     *
     * @return array<int, true>
     */
    private static function parseField(string $field, int $min, int $max, array $names, string $label): array
    {
        $field = trim($field);

        if ($field === '') {
            throw new SchedulerException(sprintf('Cron %s field must not be empty.', $label));
        }

        $values = [];

        foreach (explode(',', $field) as $part) {
            $part = trim($part);

            if ($part === '') {
                throw new SchedulerException(sprintf('Cron %s field [%s] has an empty list entry.', $label, $field));
            }

            $step = 1;
            $range = $part;

            if (str_contains($part, '/')) {
                [$range, $stepText] = explode('/', $part, 2);

                if (!ctype_digit($stepText) || (int) $stepText < 1) {
                    throw new SchedulerException(sprintf(
                        'Cron %s field [%s] has an invalid step [%s].',
                        $label,
                        $field,
                        $stepText,
                    ));
                }

                $step = (int) $stepText;
            }

            if ($range === '*') {
                $from = $min;
                $to = $max;
            } elseif (str_contains($range, '-')) {
                [$fromText, $toText] = explode('-', $range, 2);
                $from = self::value($fromText, $names, $min, $max, $label, $field);
                $to = self::value($toText, $names, $min, $max, $label, $field);

                if ($from > $to) {
                    throw new SchedulerException(sprintf(
                        'Cron %s field [%s] has a descending range [%s-%s].',
                        $label,
                        $field,
                        $fromText,
                        $toText,
                    ));
                }
            } else {
                $from = self::value($range, $names, $min, $max, $label, $field);
                $to = $from;
            }

            for ($value = $from; $value <= $to; $value += $step) {
                $values[$value] = true;
            }
        }

        ksort($values);

        return $values;
    }

    /**
     * @param array<string, int> $names
     */
    private static function value(
        string $text,
        array $names,
        int $min,
        int $max,
        string $label,
        string $field,
    ): int {
        $token = strtoupper(trim($text));

        if (isset($names[$token])) {
            return $names[$token];
        }

        if (!ctype_digit($token)) {
            throw new SchedulerException(sprintf(
                'Cron %s field [%s] has an invalid value [%s].',
                $label,
                $field,
                $text,
            ));
        }

        $value = (int) $token;

        if ($value < $min || $value > $max) {
            throw new SchedulerException(sprintf(
                'Cron %s value [%d] is outside %d-%d.',
                $label,
                $value,
                $min,
                $max,
            ));
        }

        return $value;
    }
}
