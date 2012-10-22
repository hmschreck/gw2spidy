<?php

namespace GW2Spidy\Dataset;

use \DateTime;
use \DateInterval;
use \DateTimeZone;
use GW2Spidy\Util\CacheHandler;
use GW2Spidy\DB\GoldToGemRateQuery;
use GW2Spidy\DB\GemToGoldRateQuery;

class GemExchangeDataset {
    /*
     * different posible type of datasets we can have
     */
    const TYPE_GOLD_TO_GEM = 'gold_to_gem';
    const TYPE_GEM_TO_GOLD = 'gem_to_gold';

    /*
     * just easy constants to make the code more readable
     */
    const TS_ONE_HOUR = self::TS_ONE_HOUR;
    const TS_ONE_DAY  = self::TS_ONE_DAY;
    const TS_ONE_WEEK = self::TS_ONE_WEEK;

    /**
     * one of the self::TYPE_ constants
     * @var $type
     */
    protected $type;

    /**
     * var to keep track what we last updated
     *  when doing a new update we can continue from this point
     * @var DateTime $lastUpdated
     */
    protected $lastUpdated = null;

    /**
     * var to make sure we only update the dataset once per request
     * @var boolean $updated
     */
    protected $updated = false;

    /*
     * final datasets used for output
     */
    protected $noMvAvg         = array();
    protected $dailyMvAvg      = array();
    protected $weeklyMvAvg     = array();

    /**
     * the timestamps grouped by their hour
     * @var $tsByHour
     */
    protected $tsByHour = array();

    /*
     * temporary datasets to use when replacing ticks with their hourly average
     */
    protected $hourlyNoMvAvg     = array();
    protected $hourlyDailyMvAvg  = array();
    protected $hourlyWeeklyMvAvg = array();

    /*
     * cache datasets to avoid having to filter the whole dataset all the time
     */
    protected $past24Hours = array();
    protected $pastWeek    = array();

    /**
     * @param  string    $type        should be one of self::TYPE_
     */
    public function __construct($type) {
        $this->type = $type;
    }

    /*
     * helper methods to round timestamps by hour / day / week
     */
    public static function tsHour($ts) {
        return ceil($ts / self::TS_ONE_HOUR) * self::TS_ONE_HOUR;
    }
    public static function tsDay($ts) {
        return ceil($ts / self::TS_ONE_DAY) * self::TS_ONE_DAY;
    }
    public static function tsWeek($ts) {
        return ceil($ts / self::TS_ONE_WEEK) * self::TS_ONE_WEEK;
    }

    /**
     * update the current dataset with new values from the database
     *  if posible only with values since our lastUpdated moment
     */
    public function updateDataset() {
        if ($this->updated) {
            return;
        }

        $end   = null;
        $start = $this->lastUpdated;

        $q = $this->type == self::TYPE_GEM_TO_GOLD ? GemToGoldRateQuery::create() : GoldToGemRateQuery::create();
        $q->select(array('rateDatetime', 'rate'));

        // only retrieve new ticks since last update
        if ($start) {
            $q->filterByRateDatetime($start, \Criteria::GREATER_THAN);
        }

        // fake 5 days ago so we can test new ticks being added
        $fake = new DateTime();
        $fake->sub(new DateInterval('P5D'));
        $q->filterByRateDatetime($fake, \Criteria::LESS_THAN);

        // ensure ordered data, makes our life a lot easier
        $q->orderByRateDatetime(\Criteria::ASC);

        // loop and process ticks
        $rates = $q->find();
        foreach ($rates as $rateEntry) {
            $date = new DateTime("{$rateEntry['rateDatetime']}");
            $rate = intval($rateEntry['rate']);

            $end = $date;

            $this->processTick($date, $rate);
        }

        // update for next time
        if ($end) {
            $this->lastUpdated = $end;
        }
    }

    /**
     * process a single tick,
     *  adding it to the different lines and cleaning up / aggregating old data
     *
     * @param  DateTime    $date
     * @param  int         $rate
     */
    protected function processTick(DateTime $date, $rate) {
        $ts   = $date->getTimestamp();
        $tsHr = self::tsHour($ts);

        // get the previous tick
        end($this->noMvAvg);
        $prevTs = key($this->noMvAvg);

        // add to noMvAvg
        $this->tsByHour[$tsHr][] = $ts;
        $this->noMvAvg[$ts] = array($ts * 1000, $rate);

        // add to past 24 hours
        $this->past24Hours[$ts] = $rate;

        // add to past 24 hours
        $this->pastWeek[$ts] = $rate;

        /*
         * we process the gap between the previous tick and our tick
         * since everything before the previous tick has already been processed!
         */
        if ($prevTs) {
            /*
             * remove ticks from the past24Hours cache if they're older then 24 hours
             *  but younger then what the previous tick should have already removed
             */
            $thresMin = self::tsHour($prevTs - self::TS_ONE_DAY);
            $thresMax = self::tsHour($ts - self::TS_ONE_DAY);
            while ($thresMin < $thresMax) {
                $thisTsHour = self::tsHour($thresMin);
                $thisHour   = array();

                if (isset($this->tsByHour[$thisTsHour])) {
                    foreach (array_unique($this->tsByHour[$thisTsHour]) as $tickTs) {
                        unset($this->past24Hours[$tickTs]);
                    }
                }

                $thresMin += self::TS_ONE_HOUR;
            }

            /*
             * remove ticks from the pastWeek cache if they're older then a week
             *  but younger then what the previous tick should have already removed
             */
            $thresMin = self::tsHour($prevTs - self::TS_ONE_WEEK);
            $thresMax = self::tsHour($ts - self::TS_ONE_WEEK);
            while ($thresMin < $thresMax) {
                $thisTsHour = self::tsHour($thresMin);
                $thisHour   = array();

                if (isset($this->tsByHour[$thisTsHour])) {
                    foreach (array_unique($this->tsByHour[$thisTsHour]) as $tickTs) {
                        unset($this->pastWeek[$tickTs]);
                    }
                }

                $thresMin += self::TS_ONE_HOUR;
            }

            /*
             * aggregate ticks older then 24 hours into 1 tick per hour (averaged out for that hour)
             *  we do this exactly the same for all datasets so that they all align nicely
             */
            $thresMin = self::tsHour($prevTs - self::TS_ONE_DAY);
            $thresMax = self::tsHour($ts - self::TS_ONE_DAY);
            while ($thresMin < $thresMax) {
                $thisTsHour = self::tsHour($thresMin);
                $thisHour   = array();

                if (isset($this->tsByHour[$thisTsHour])) {
                    // (re)calculate the average of this ticks hour
                    $hourNoMvAvg = array();
                    $hourDailyMvAvg = array();
                    $hourWeeklyMvAvg = array();
                    foreach ($this->tsByHour[$thisTsHour] as $tickTs) {
                        $hourNoMvAvg[] = $this->noMvAvg[$tickTs][1];
                        $hourDailyMvAvg[] = $this->dailyMvAvg[$tickTs][1];
                        $hourWeeklyMvAvg[] = $this->weeklyMvAvg[$tickTs][1];
                    }
                    $this->hourlyNoMvAvg[$thisTsHour] = array_sum($hourNoMvAvg) / count($hourNoMvAvg);
                    $this->hourlyDailyMvAvg[$thisTsHour] = array_sum($hourDailyMvAvg) / count($hourDailyMvAvg);
                    $this->hourlyWeeklyMvAvg[$thisTsHour] = array_sum($hourWeeklyMvAvg) / count($hourWeeklyMvAvg);

                    // remove old ticks
                    foreach (array_unique($this->tsByHour[$thisTsHour]) as $tickTs) {
                        unset($this->noMvAvg[$tickTs]);
                        unset($this->dailyMvAvg[$tickTs]);
                        unset($this->weeklyMvAvg[$tickTs]);
                    }

                    // insert hourly ticks
                    $this->noMvAvg[$thisTsHour] = array($thisTsHour * 1000, $this->hourlyNoMvAvg[$thisTsHour]);
                    $this->dailyMvAvg[$thisTsHour] = array($thisTsHour * 1000, $this->hourlyDailyMvAvg[$thisTsHour]);
                    $this->weeklyMvAvg[$thisTsHour] = array($thisTsHour * 1000, $this->hourlyWeeklyMvAvg[$thisTsHour]);
                    $this->tsByHour[$thisTsHour] = array($thisTsHour);
                }

                $thresMin += self::TS_ONE_HOUR;
            }
        }

        // calculate new daily mv avg tick
        if (count($this->past24Hours)) {
            $dailyMvAvg = array_sum($this->past24Hours) / count($this->past24Hours);
            $this->dailyMvAvg[$ts] = array($ts * 1000, $dailyMvAvg);
        }

        // calculate new weekly mv avg tick
        if (count($this->pastWeek)) {
            $weeklyMvAvg = array_sum($this->pastWeek) / count($this->pastWeek);
            $this->weeklyMvAvg[$ts] = array($ts * 1000, $weeklyMvAvg);
        }
    }

    public function getNoMvAvgDataForChart() {
        $this->updateDataset();

        ksort($this->noMvAvg);

        return array_values($this->noMvAvg);
    }

    public function getDailyMvAvgDataForChart() {
        $this->updateDataset();

        ksort($this->dailyMvAvg);

        return array_values($this->dailyMvAvg);
    }

    public function getWeeklyMvAvgDataForChart() {
        $this->updateDataset();

        ksort($this->weeklyMvAvg);

        return array_values($this->weeklyMvAvg);
    }

    /**
     * clean up any interal cache vars we had
     *  and mark updated = false so next time the object is retrieved from cache it will be updated again
     */
    public function __wakeup() {
        $this->hourlyWeeklyMvAvg = array();
        $this->hourlyDailyMvAvg = array();
        $this->hourlyNoMvAvg = array();
        $this->updated = false;
    }
}
