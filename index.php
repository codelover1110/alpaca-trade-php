<?php
require './vendor/autoload.php';

use Alpaca\Alpaca;
use Polygon\Polygon;

class Quote
{
    /**
     * We use Quote objects to represent the bid/ask spread. When we encounter a
     * 'level change', a move of exactly 1 penny, we may attempt to make one
     * trade. Whether or not the trade is successfully filled, we do not submit
     * another trade until we see another level change.
     * Note: Only moves of 1 penny are considered eligible because larger moves
     * could potentially indicate some newsworthy event for the stock, which this
     * algorithm is not tuned to trade.
     */
    public $prevBid;
    public $prevAsk;
    public $prevSpread;
    public $bid;
    public $ask;
    public $bidSize;
    public $askSize;
    public $spread;
    public $traded;
    public $levelCnt;
    public $time;
    public $priceDeviation;


    public function __construct()
    {
        $this->prevBid = 0;
        $this->prevAsk = 0;
        $this->prevSpread = 0;
        $this->bid = 0;
        $this->ask = 0;
        $this->bidSize = 0;
        $this->askSize = 0;
        $this->spread = 0;
        $this->traded = true;
        $this->levelCnt = 1;
        $this->time = 0;
    }

    public function reset()
    {
        // Called when a level change happens
        $this->traded = false;
        $this->levelCnt += 1;
    }

    public function update($data)
    {
        echo '<pre>';
        print_r($data);
        print_r(round($data['askprice'] - $data['bidprice'], 2));
        // Update bid and ask sizes and timestamp
        $this->bidSize = $data['bidsize'];
        $this->askSize = $data['asksize'];

        // Check if there has been a level change
        if ($this->bid != $data['bidprice'] && $this->ask != $data['askprice'] && round($data['askprice'] - $data['bidprice'], 2) == $this->priceDeviation) {
            print_r("===============");
            // Update bids and asks and time of level change
            $this->prevBid = $this->bid;
            $this->prevAsk = $this->ask;
            $this->bid = $data['bidprice'];
            $this->ask = $data['askprice'];
            $this->time = $data['timestamp'];
            // Update spreads
            $this->prevSpread = round($this->prevAsk - $this->prevBid, 3);
            print_r($this->prevSpread);
            $this->spread = round($this->ask - $this->bid, 3);
            echo '<br>' . 'Level change : ' . $this->prevBid . ' ' . $this->prevAsk . ' ' . $this->prevSpread . ' ' . $this->bid . ' ' . $this->ask . ' ' . $this->spread . '<br>';
            // If change is from one penny spread level to a different penny
            // spread level, then initialize for new level (reset stale vars)
            if ($this->prevSpread == $this->priceDeviation) {
                print_r("reset=============>");
                $this->reset();
            }
        }
    }
}

class Position
{
    /**
     * The position object is used to track how many shares we have. We need to
     * keep track of this so our position size doesn't inflate beyond the level
     * we're willing to trade with. Because orders may sometimes be partially
     * filled, we need to keep track of how many shares are "pending" a buy or
     * sell as well as how many have been filled into our account.
     */
    public $ordersFilledAmount;
    public $pendingBuyShares;
    public $pendingSellShares;
    public $totalShares;
    public $orderIds;

    public function __construct()
    {
        $this->ordersFilledAmount = array();
        $this->pendingBuyShares = 0;
        $this->pendingSellShares = 0;
        $this->totalShares = 0;
        $this->orderId = '';
    }


    public function updatePendingBuyShares($quantity)
    {
        $this->pendingBuyShares += $quantity;
    }

    public function updatePendingSellShares($quantity)
    {
        $this->pendingSellShares += $quantity;
    }

    public function updateTotalShares($quantity)
    {
        $this->totalShares += $quantity;
    }

    public function updateFilledAmount($orderId, $newAmount, $side)
    {
        $this->orderId = $orderId;
        $oldAmount = $this->ordersFilledAmount[$orderId];
        if ($newAmount > $oldAmount) {
            if ($side == 'buy') {
                $this->updatePendingBuyShares($oldAmount - $newAmount);
                $this->updateTotalShares($newAmount - $oldAmount);
            } else {
                $this->updatePendingSellShares($oldAmount - $newAmount);
                $this->updateTotalShares($oldAmount - $newAmount);
            }
            $this->ordersFilledAmount[$orderId] = $newAmount;
        }
    }

    public function removePendingOrder($orderId, $side, $quantity)
    {
        $oldAmount = $this->ordersFilledAmount[$orderId];
        if ($side == 'buy') {
            $this->updatePendingBuyShares($oldAmount - $quantity);
        } else {
            $this->updatePendingSellShares($oldAmount - $quantity);
        }
        unset($this->ordersFilledAmount[$orderId]);

    }
}

class Trade
{
    private $alpacaApi;
    private $polygonApi;
    private $symbol;
    private $quote;
    private $position;
    private $maxShares;
    private $interval;
    private $envFlag;

    public function __construct()
    {
        $this->maxShares = 100;
    }

    public function onTradeUpdates()
    {
        /**
         * We got an update on one of the orders we submitted. We need to
         * update our position with the new information.
         */

        $orders = $this->position->ordersFilledAmount;
        foreach ($orders as $key => $val) {
            $order = $this->alpacaApi->orders()->get($key);
            $event = $order['status'];
            if ($this->symbol == $order['symbol']) {
                if ($event == 'fill') {
                    if ($order['side'] == 'buy') {
                        $this->position->updateTotalShares(intval($order['filled_qty']));
                    } else {
                        $this->position->updateTotalShares((-1) * intval($order['filled_qty']));
                    }
                } elseif ($event == 'partial_fill') {
                    $this->position->updateFilledAmount($order['id'], intval($order['filled_qty']), $order['side']);
                } elseif ($event == 'canceled' || $event == 'rejected') {
                    $this->position->removePendingOrder($order['id'], $order['side'], $this->quantity);
                }
                $this->finalOrderId = $order['id'];
            }
        }
    }

    public function onTrade()
    {
        $data = $this->polygonApi->stocks()->getSnapshot($this->symbol);
      
        if ($this->quote->traded) {
            return;
        }

        if ($data['min']->v >= $this->quantity) {
            /**
             * The trade was large enough to follow, so we check to see if
             * we're ready to trade. We also check to see that the
             * bid vs ask quantities (order book imbalance) indicate
             * a movement in that direction. We also want to be sure that
             * we're not buying or selling more than we should.
             */
            if ($data['min']->c == $this->quote->ask && $this->quote->bidSize > ($this->quote->askSize * 1.8) && ($this->position->totalShares + $this->position->pendingBuyShares) < $this->maxShares) {
                // Everything looks right, so we submit our buy at the ask
                echo '<pre>';
                print_r("===Buy START===");
                        
                try {
                    // dev environment with API key or live environment
                    $o = $this->alpacaApi->orders()->create([
                        // stock to purchase
                        'symbol' => $this->symbol,
                        // how many shares
                        'qty' => $this->quantity,
                        // buy or sell
                        'side' => 'buy',
                        // market, limit, stop, or stop_limit
                        'type' => 'limit',
                        // day, gtc, opg, cls, ioc, fok.
                        // @see https://docs.alpaca.markets/orders/#time-in-force
                        'time_in_force' => 'day',
                        // required if type is limit or stop_limit
                        'limit_price' => strval($this->quote->ask),
                        // required if type is stop or stop_limit
                        // 'stop_price' => 0,
                        'extended_hours' => false,
                    ]);

                    // Approximate an IOC order by immediately cancelling
                    $this->alpacaApi->orders()->cancel($o['id']);
                    $this->position->updatePendingBuyShares($this->quantity);
                    $this->position->ordersFilledAmount[$o['id']] = 0;


                    echo '<br>' . 'Buy at ' . strval($this->quote->ask) . '<br>';
                    $this->quote->traded = true;
                } catch (Exception $e) {
                    echo '<br>' . 'Message: ' . $e->getMessage() . '<br>';
                }
            } elseif ($data['min']->c >= $this->quote->bid && $this->quote->askSize > ($this->quote->bidSize * 1.8) && ($this->position->totalShares - $this->position->pendingSellShares) >= $this->quantity) {
                // Everything looks right, so we submit our sell at the bid
                echo '<pre>';
                print_r("===SELL START===");
                try {
                     // dev environment with API key or live environment
                     $o = $this->alpacaApi->orders()->create([
                        // stock to purchase
                        'symbol' => $this->symbol,
                        // how many shares
                        'qty' =>  $this->max_shares,
                        // buy or sell
                        'side' => 'sell',
                        // market, limit, stop, or stop_limit
                        'type' => 'limit',
                        // day, gtc, opg, cls, ioc, fok.
                        // @see https://docs.alpaca.markets/orders/#time-in-force
                        'time_in_force' => 'day',
                        // required if type is limit or stop_limit
                        'limit_price' =>  strval($this->quote->bid),
                        // required if type is stop or stop_limit
                        // 'stop_price' => 0,
                        'extended_hours' => false,
                    ]);
                    $this->alpacaApi->orders()->cancel($o['id']);
                    $this->position->updatePendingSellShares($this->quantity);
                    $this->position->ordersFilledAmount[$o['id']] = 0;

                    echo '<br>' . 'Sell at ' . strval($this->quote->bid) . '<br>';
                    $this->quote->traded = true;
                } catch (Exception $e) {
                    echo '<br>' . 'Message: ' . $e->getMessage() . '<br>';
                }
            }
        }
    }

    public function setInterval($seconds)
    {
        ob_implicit_flush(true);
        ob_end_flush();
        while (true) {
            $this->quote->update($this->polygonApi->stocks()->getLastQuote($this->symbol));
            $this->onTradeUpdates();
            $this->onTrade();

            sleep($seconds);
        }
    }

    public function run($args)
    {
        $keyId = $args['keyId'];
        $secretKey = $args['secretKey'];
        $keyIdLive = $args['keyIdLive'];
        $envFlag = $args['envFlag'];

        // Create an API object which can be used to submit orders, etc.
        if ($envFlag != 0) {
            if ($keyId != '' && $secretKey != '') {
                $this->alpacaApi = new Alpaca($keyId, $secretKey);
                $this->polygonApi = new Polygon($keyIdLive);
            }
        } else {
            return;
        }

        $this->symbol = strtoupper($args['symbol']);
        $this->maxShares = $args['totalShares'];
        $this->quantity = $args['quantity'];
        $this->interval = $args['interval'];
        $this->envFlag = $args['envFlag'];
        $this->quote = new Quote();
        $this->quote->priceDeviation = $args['priceDeviation'];
        $this->position = new Position();
        $this->setInterval($this->interval);
    }
}

$envFlag = 1; // 1: dev environment with API key, 2: live environment

$args = array(
    'symbol'    => 'SNAP',
    'totalShares' => 500,
    'keyIdLive'     => 'AKM74OSX70Y5AXY572IJ',
    'secretKeyLive' => 'yAgVIh59BzOiNqrazFRvzElFXbDJjnk3jTHYrqUz',
    'quantity'  => 100,
    'envFlag'   => $envFlag,
    'interval' => 2,
    'priceDeviation' => 0.01,
    'keyId'     => 'PKS42FUVOBH6ZC2HVRJ4',
    'secretKey' => 'g06cmVU5CGxYekhtsrgD/Q1yloA7e6tboFPDd0ZI',
   
);

$trade = new Trade();
$trade->run($args);
