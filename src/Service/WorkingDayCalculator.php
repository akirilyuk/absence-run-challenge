<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Employee;

final class WorkingDayCalculator
{
    public function __construct(private readonly HolidayCalendar $holidays)
    {
    }

    /**
     * Count working days in a range (Mon-Fri, minus holidays).
     *
     * @return array<int, float> map of year => working days
     */
    public function countWorkingDaysByYear(Employee $employee, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        if ($end < $start) {
            return [];
        }

        $counts = [];
        $period = new \DatePeriod($start, new \DateInterval('P1D'), $end->modify('+1 day'));

        foreach ($period as $day) {
            $weekday = (int) $day->format('N');
            if ($weekday >= 6) {
                continue;
            }

            $year = (int) $day->format('Y');
            $holidayDates = $this->holidays->holidaysForState($employee->getFederalState(), $year);
            $dateKey = $day->format('Y-m-d');
            if (\in_array($dateKey, $holidayDates, true)) {
                continue;
            }

            $counts[$year] = ($counts[$year] ?? 0.0) + 1.0;
        }

        ksort($counts);

        return $counts;
    }
}
