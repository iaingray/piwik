<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 * @category Piwik
 * @package Piwik
 */
namespace Piwik\Period;

use Exception;
use Piwik\Common;
use Piwik\Period;
use Piwik\Date;
use Piwik\Period\Month;
use Piwik\Period\Week;

/**
 * from a starting date to an ending date
 *
 * @package Piwik
 * @subpackage Period
 */
class Range extends Period
{
    protected $label = 'range';

    /**
     * @param string $strPeriod
     * @param string $strDate
     * @param string $timezone
     * @param bool|Date $today
     */
    public function __construct($strPeriod, $strDate, $timezone = 'UTC', $today = false)
    {
        $this->strPeriod = $strPeriod;
        $this->strDate = $strDate;
        $this->defaultEndDate = null;
        $this->timezone = $timezone;
        if ($today === false) {
            $today = Date::factory('today', $this->timezone);
        }
        $this->today = $today;
    }

    /**
     * Returns the current period as a localized short string
     *
     * @return string
     */
    public function getLocalizedShortString()
    {
        //"30 Dec 08 - 26 Feb 09"
        $dateStart = $this->getDateStart();
        $dateEnd = $this->getDateEnd();
        $template = Piwik_Translate('CoreHome_ShortDateFormatWithYear');
        $shortDateStart = $dateStart->getLocalized($template);
        $shortDateEnd = $dateEnd->getLocalized($template);
        $out = "$shortDateStart - $shortDateEnd";
        return $out;
    }

    /**
     * Returns the current period as a localized long string
     *
     * @return string
     */
    public function getLocalizedLongString()
    {
        return $this->getLocalizedShortString();
    }

    /**
     * Returns the start date of the period
     *
     * @return Date
     * @throws Exception
     */
    public function getDateStart()
    {
        $dateStart = parent::getDateStart();
        if (empty($dateStart)) {
            throw new Exception("Specified date range is invalid.");
        }
        return $dateStart;
    }

    /**
     * Returns the current period as a string
     *
     * @return string
     */
    public function getPrettyString()
    {
        $out = Piwik_Translate('General_DateRangeFromTo', array($this->getDateStart()->toString(), $this->getDateEnd()->toString()));
        return $out;
    }

    /**
     *
     * @param string $period
     * @param Date $date
     * @param int $n
     * @throws Exception
     * @return Date
     */
    static public function removePeriod($period, Date $date, $n)
    {
        switch ($period) {
            case 'day':
                $startDate = $date->subDay($n);
                break;

            case 'week':
                $startDate = $date->subDay($n * 7);
                break;

            case 'month':
                $startDate = $date->subMonth($n);
                break;

            case 'year':
                $startDate = $date->subMonth(12 * $n);
                break;
            default:
                throw new Exception('The period parameter is invalid');
                break;
        }
        return $startDate;
    }

    protected function getMaxN($lastN)
    {
        switch ($this->strPeriod) {
            case 'day':
                $lastN = min($lastN, 5 * 365);
                break;

            case 'week':
                $lastN = min($lastN, 10 * 52);
                break;

            case 'month':
                $lastN = min($lastN, 10 * 12);
                break;

            case 'year':
                $lastN = min($lastN, 10);
                break;
        }
        return $lastN;
    }

    /**
     * Sets the default end date of the period
     *
     * @param Date $oDate
     */
    public function setDefaultEndDate(Date $oDate)
    {
        $this->defaultEndDate = $oDate;
    }

    /**
     * Generates the subperiods
     *
     * @throws Exception
     */
    protected function generate()
    {
        if ($this->subperiodsProcessed) {
            return;
        }
        parent::generate();

        if (preg_match('/(last|previous)([0-9]*)/', $this->strDate, $regs)) {
            $lastN = $regs[2];
            $lastOrPrevious = $regs[1];
            if (!is_null($this->defaultEndDate)) {
                $defaultEndDate = $this->defaultEndDate;
            } else {
                $defaultEndDate = Date::factory('now', $this->timezone);
            }

            $period = $this->strPeriod;
            if ($period == 'range') {
                $period = 'day';
            }

            if ($lastOrPrevious == 'last') {
                $endDate = $defaultEndDate;
            } elseif ($lastOrPrevious == 'previous') {
                $endDate = self::removePeriod($period, $defaultEndDate, 1);
            }

            $lastN = $this->getMaxN($lastN);

            // last1 means only one result ; last2 means 2 results so we remove only 1 to the days/weeks/etc
            $lastN--;
            $lastN = abs($lastN);

            $startDate = self::removePeriod($period, $endDate, $lastN);
        } elseif ($dateRange = Range::parseDateRange($this->strDate)) {
            $strDateStart = $dateRange[1];
            $strDateEnd = $dateRange[2];
            $startDate = Date::factory($strDateStart);

            if ($strDateEnd == 'today') {
                $strDateEnd = 'now';
            } elseif ($strDateEnd == 'yesterday') {
                $strDateEnd = 'yesterdaySameTime';
            }
            // we set the timezone in the Date object only if the date is relative eg. 'today', 'yesterday', 'now'
            $timezone = null;
            if (strpos($strDateEnd, '-') === false) {
                $timezone = $this->timezone;
            }
            $endDate = Date::factory($strDateEnd, $timezone);
        } else {
            throw new Exception(Piwik_TranslateException('General_ExceptionInvalidDateRange', array($this->strDate, ' \'lastN\', \'previousN\', \'YYYY-MM-DD,YYYY-MM-DD\'')));
        }
        if ($this->strPeriod != 'range') {
            $this->fillArraySubPeriods($startDate, $endDate, $this->strPeriod);
            return;
        }
        $this->processOptimalSubperiods($startDate, $endDate);
        // When period=range, we want End Date to be the actual specified end date,
        // rather than the end of the month / week / whatever is used for processing this range
        $this->endDate = $endDate;
    }

    /**
     * Given a date string, returns false if not a date range,
     * or returns the array containing date start, date end
     *
     * @param string $dateString
     * @return mixed  array(1 => dateStartString, 2 => dateEndString ) or false if the input was not a date range
     */
    static public function parseDateRange($dateString)
    {
        $matched = preg_match('/^([0-9]{4}-[0-9]{1,2}-[0-9]{1,2}),(([0-9]{4}-[0-9]{1,2}-[0-9]{1,2})|today|now|yesterday)$/D', trim($dateString), $regs);
        if (empty($matched)) {
            return false;
        }
        return $regs;
    }

    protected $endDate = null;

    /**
     * Returns the end date of the period
     *
     * @return null|Date
     */
    public function getDateEnd()
    {
        if (!is_null($this->endDate)) {
            return $this->endDate;
        }
        return parent::getDateEnd();
    }

    /**
     * Determine which kind of period is best to use
     * See Range.test.php
     *
     * @param $startDate
     * @param $endDate
     */
    protected function processOptimalSubperiods($startDate, $endDate)
    {
        while ($startDate->isEarlier($endDate)
            || $startDate == $endDate) {
            $endOfPeriod = null;

            $month = new Month($startDate);
            $endOfMonth = $month->getDateEnd();
            $startOfMonth = $month->getDateStart();
            if ($startDate == $startOfMonth
                && ($endOfMonth->isEarlier($endDate)
                    || $endOfMonth == $endDate
                    || $endOfMonth->isLater($this->today)
                )
                // We don't use the month if
                // the end day is in this month, is before today, and month not finished
                && !($endDate->isEarlier($this->today)
                    && $this->today->toString('Y') == $endOfMonth->toString('Y')
                    && $this->today->compareMonth($endOfMonth) == 0)
            ) {
                $this->addSubperiod($month);
                $endOfPeriod = $endOfMonth;
            } else {
                // From start date,
                //  Process end of week
                $week = new Week($startDate);
                $startOfWeek = $week->getDateStart();
                $endOfWeek = $week->getDateEnd();

                $useMonthsNextIteration = $startDate->addPeriod(2, 'month')->setDay(1)->isEarlier($endDate);
                if ($useMonthsNextIteration
                    && $endOfWeek->isLater($endOfMonth)
                ) {
                    $this->fillArraySubPeriods($startDate, $endOfMonth, 'day');
                    $endOfPeriod = $endOfMonth;
                } //   If end of this week is later than end date, we use days
                elseif ($endOfWeek->isLater($endDate)
                    && ($endOfWeek->isEarlier($this->today)
                        || $endDate->isEarlier($this->today))
                ) {
                    $this->fillArraySubPeriods($startDate, $endDate, 'day');
                    break 1;
                } elseif ($startOfWeek->isEarlier($startDate)
                    && $endOfWeek->isEarlier($this->today)
                ) {
                    $this->fillArraySubPeriods($startDate, $endOfWeek, 'day');
                    $endOfPeriod = $endOfWeek;
                } else {
                    $this->addSubperiod($week);
                    $endOfPeriod = $endOfWeek;
                }
            }
            $startDate = $endOfPeriod->addDay(1);
        }
    }

    /**
     * Adds new subperiods
     *
     * @param Date $startDate
     * @param Date $endDate
     * @param string $period
     */
    protected function fillArraySubPeriods($startDate, $endDate, $period)
    {
        $arrayPeriods = array();
        $endSubperiod = Period::factory($period, $endDate);
        $arrayPeriods[] = $endSubperiod;

        // set end date to start of end period since we're comparing against start date.
        $endDate = $endSubperiod->getDateStart();
        while ($endDate->isLater($startDate)) {
            $endDate = self::removePeriod($period, $endDate, 1);
            $subPeriod = Period::factory($period, $endDate);
            $arrayPeriods[] = $subPeriod;
        }
        $arrayPeriods = array_reverse($arrayPeriods);
        foreach ($arrayPeriods as $period) {
            $this->addSubperiod($period);
        }
    }

    /**
     * Returns the date that is one period before the supplied date.
     *
     * @param bool|string $date   The date to get the last date of.
     * @param bool|string $period The period to use (either 'day', 'week', 'month', 'year');
     *
     * @return array An array with two elements, a string for the date before $date and
     *               a Period instance for the period before $date.
     */
    public static function getLastDate($date = false, $period = false)
    {
        if ($date === false) {
            $date = Common::getRequestVar('date');
        }

        if ($period === false) {
            $period = Common::getRequestVar('period');
        }

        // can't get the last date for range periods & dates that use lastN/previousN
        $strLastDate = false;
        $lastPeriod = false;
        if ($period != 'range' && !preg_match('/(last|previous)([0-9]*)/', $date, $regs)) {
            if (strpos($date, ',')) // date in the form of 2011-01-01,2011-02-02
            {
                $rangePeriod = new Range($period, $date);

                $lastStartDate = Range::removePeriod($period, $rangePeriod->getDateStart(), $n = 1);
                $lastEndDate = Range::removePeriod($period, $rangePeriod->getDateEnd(), $n = 1);

                $strLastDate = "$lastStartDate,$lastEndDate";
            } else {
                $lastPeriod = Range::removePeriod($period, Date::factory($date), $n = 1);
                $strLastDate = $lastPeriod->toString();
            }
        }

        return array($strLastDate, $lastPeriod);
    }
}
