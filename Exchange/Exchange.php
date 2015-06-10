<?php

namespace h4kuna\Exchange;

use ArrayIterator;
use DateTime;
use h4kuna\Exchange\Currency\IProperty;
use h4kuna\Exchange\Driver\Download;
use h4kuna\Exchange\Storage\IWarehouse;
use h4kuna\Exchange\Storage\IRequestManager;
use h4kuna\Number\INumberFormat;
use h4kuna\Number\NumberFormat;
use h4kuna\Number\Tax;
use h4kuna\Number\Vat;
use Nette\Reflection\Property;

/**
 *
 * @author Milan Matějček
 * @since 2009-06-22 - version 0.5
 * @property string $default
 * @property string $web
 */
class Exchange extends ArrayIterator {

    /**
     * History instances
     *
     * @var array
     */
    private static $history = array();

    /**
     * Default currency "from" input
     *
     * @var IProperty
     */
    private $default;

    /**
     * Display currency "to" output
     *
     * @var IProperty
     */
    private $web;



// <editor-fold defaultstate="collapsed" desc="Private dependencies">

    /** @var Tax */
    protected $tax;

    /**
     * Last changed value
     *
     * @var INumberFormat
     */
    private $lastChange;

    /** @var IWarehouse */
    private $warehouse;

    /** @var NumberFormat */
    private $number;

    /** @var IRequestManager */
    private $request;

// </editor-fold>

    public function __construct(IWarehouse $warehouse, IRequestManager $request) {
        parent::__construct();
        $this->warehouse = $warehouse;
        $this->request = $request;
        self::$history[$warehouse->getName()] = $this;
    }

// <editor-fold defaultstate="collapsed" desc="Setters">

    /**
     * Set default "from" currency
     *
     * @param string|Property $code
     * @return Exchange
     */
    public function setDefault($code) {
        $this->default = $this->offsetGet($code);
        return $this;
    }

    /**
     * Set default custom render number
     *
     * @param NumberFormat $nf
     * @return Exchange
     */
    public function setDefaulFormat(INumberFormat $nf) {
        $this->number = $nf;
        return $this;
    }

    /**
     *
     * @param DateTime $date
     * @return Exchange
     */
    public function setDate(DateTime $date) {
        $key = $this->warehouse->loadNameByDate($date);
        if (isset(self::$history[$key])) {
            return self::$history[$key];
        }
        $warehouse = $this->warehouse->setDate($date);
        return $this->bindMe($warehouse);
    }

    /**
     *
     * @param Download $driver
     * @return Exchange
     */
    public function setDriver(Download $driver) {
        $key = $this->warehouse->loadNameByDriver($driver);
        if (isset(self::$history[$key])) {
            return self::$history[$key];
        }
        $warehouse = $this->warehouse->setDriver($driver);
        return $this->bindMe($warehouse);
    }

    /**
     * Set global VAT
     *
     * @param type $v
     * @param bool $in
     * @param bool $out
     * @return Exchange
     */
    public function setVat($v, $in, $out) {
        $this->tax = new Tax($v);
        $this->tax->setVatIO($in, $this->request->loadParamVat($out));
        return $this;
    }

    /**
     * Set currency "to"
     *
     * @param string $code
     * @param bool $session
     * @return Exchange
     */
    public function setWeb($code, $session = FALSE) {
        $this->web = $this->offsetGet($code);
        if ($session) {
            $this->request->setSessionCurrency($this->web->getCode());
        }
        return $this;
    }

// </editor-fold>
// <editor-fold defaultstate="collapsed" desc="ArrayIterator API">
    /**
     * Load currency property
     *
     * @param string|IProperty $index
     * @return IProperty
     * @throws ExchangeException
     */
    public function offsetGet($index) {
        if ($index instanceof IProperty) {
            return $index;
        }
        $index = strtoupper($index);
        if ($this->offsetExists($index)) {
            return parent::offsetGet($index);
        }
        throw new ExchangeException('Undefined currency code: ' . $index . ', you must call loadCurrency before.');
    }

// </editor-fold>
// <editor-fold defaultstate="collapsed" desc="Main API">
    /**
     * Transfer number by exchange rate
     *
     * @param float|int|string $price number
     * @param string|FALSE $from default currency, FALSE no transfer
     * @param string $to output currency
     * @param int $round
     * @param int|float|Vat $vat
     * @return float|NULL
     */
    public function change($price, $from = NULL, $to = NULL, $round = NULL, $vat = NULL) {
        if (!is_numeric($price)) {
            return NULL;
        }

        $to = $this->offsetGet($to ? $to : $this->getWeb());

        if ($from === NULL || $from) {
            $from = $this->offsetGet($from ? $from : $this->getDefault());

            if ($to !== $from) {
                $price *= $to->getRate() / $from->getRate();
            }
        }

        if ($this->tax) {
            $price = $this->tax->taxation($price, $vat);
        }

        if ($round !== NULL) {
            $price = round($price, $round);
        }

        return $price;
    }

    /**
     * Count, format price
     *
     * @param number $number
     * @param string|bool $from FALSE currency doesn't counting, NULL set actual
     * @param string $to output currency, NULL set actual
     * @param int|float|Vat $vat
     * @return string
     */
    public function format($number, $from = NULL, $to = NULL, $vat = NULL) {
        $to = $this->offsetGet($to ? $to : $this->getWeb());
        $number = $this->change($number, $from, $to, NULL, $vat);
        $this->lastChange = $to->getFormat();
        return $this->lastChange->render($number);
    }

    /**
     *
     * @param float $number
     * @param string|FALSE $to
     * @param int|float|Vat $vat
     * @return string
     */
    public function formatTo($number, $to, $vat = NULL) {
        return $this->format($number, NULL, $to, $vat);
    }

    /**
     * Price with VAT every time
     *
     * @return string
     */
    public function formatVat() {
        $number = $this->lastChange->getNumber();
        if ($this->tax->isVatOn()) {
            return $this->lastChange->render($number);
        }
        $this->tax->vatOn();
        $number = $this->lastChange->render($this->tax->taxation($number));
        $this->tax->vatOff();
        return $number;
    }

    /**
     * LoadAll currencies in storage
     *
     * @return Exchange
     */
    public function loadAll() {
        foreach ($this->warehouse->getListCurrencies() as $code) {
            if (!$this->offsetExists($code)) {
                $this->loadCurrency($code);
            }
        }

        return $this;
    }

    /**
     * Load currency by code
     *
     * @param string $code
     * @return IProperty
     */
    public function loadCurrency($code, $property = array()) {
        try {
            $currency = $this->warehouse->loadCurrency($code);
            if (!$this->default) {
                $this->setDefault($currency);
            }
        } catch (ExchangeException $e) {
            if (!$this->default) {
                throw new ExchangeException('Let\'s define possible currency code. Not this: ' . $code);
            }
            $currency = $this->default;
        }

        $code = $currency->getCode();

        if ($property || !isset($this[$code])) {
            if (!$property) {
                $profil = $this->getDefaultFormat();
                $profil->setSymbol($code);
            } elseif (is_array($property)) {
                $profil = $this->getDefaultFormat();
                $profil->setSymbol($code);
                foreach ($property as $k => $v) {
                    $k = 'set' . ucfirst($k);
                    $profil->$k($v);
                }
            } else {
                $profil = $property;
            }

            if (!($profil instanceof INumberFormat)) {
                throw new ExchangeException('Property of currency must be array or instance of INumberFormat');
            }

            $this[$code] = $currency->setFormat($profil);
            $currency->default = &$this->default;
        }

        return $currency;
    }

    /** @return bool */
    public function isVatOn() {
        if ($this->tax === NULL) {
            throw new ExchangeException('Let\'s define vat by setVat().');
        }
        return $this->tax->isVatOn();
    }

    /** @deprecated */
    public function addHistory($code, $rate) {
        trigger_error(__METHOD__ . '() is deprecated; use $this->addRate() instead.', E_USER_DEPRECATED);
        $this->offsetGet($code)->pushRate($rate);
        return $this;
    }

    /** @deprecated */
    public function removeHistory($code) {
        trigger_error(__METHOD__ . '() is deprecated; use $this->removeRate() instead.', E_USER_DEPRECATED);
        $this->offsetGet($code)->popRate();
        return $this;
    }

    /**
     * Add history rate for rating
     *
     * @param string $code
     * @param float $rate
     * @return Exchange
     */
    public function addRate($code, $rate) {
        $this->offsetGet($code)->pushRate($rate);
        return $this;
    }

    /**
     * Remove history rating
     * 
     * @param string $code
     * @return Exchange
     */
    public function removeRate($code) {
        $this->offsetGet($code)->popRate();
        return $this;
    }

// </editor-fold>
// <editor-fold defaultstate="collapsed" desc="Getters">

    /** @var IProperty */
    public function getDefault() {
        if (!$this->default) {
            throw new ExchangeException('Let\'s define currency by method loadCurrency() and first is default.');
        }
        return $this->default;
    }

    /**
     * Prototype INumberFormat
     *
     * @return INumberFormat
     */
    public function getDefaultFormat() {
        if (!$this->number) {
            $this->number = new NumberFormat;
        }

        return clone $this->number;
    }

    /** @return INumberFormat */
    public function getLastChange() {
        return $this->getLastChange;
    }

    /** @return Tax */
    public function getVat() {
        if (!$this->tax) {
            return NULL;
        }
        return $this->tax->getVat()->getPercent();
    }

    /** @return IProperty */
    public function getWeb() {
        if (!$this->web) {
            $code = $this->request->loadParamCurrency($this->getDefault()->getCode());
            $this->web = $this->offsetGet($code);
        }
        return $this->web;
    }

    /** @var IWarehouse */
    public function getWarehouse() {
        return $this->warehouse;
    }

    /** @return IRequestManager */
    public function getRequestManager() {
        return $this->request;
    }

// </editor-fold>
// <editor-fold defaultstate="collapsed" desc="Session setup">

    /** @return string */
    public function getName() {
        return $this->warehouse->getName();
    }

    /**
     * 
     * @param string $name
     * @return Exchange
     */
    public function getHistory($name) {
        return isset(self::$history[$name]) ? self::$history[$name] : NULL;
    }

    /**
     *
     * @param IWarehouse $warehouse
     * @return Exchange
     */
    private function bindMe(IWarehouse $warehouse) {
        $exchange = new static($warehouse, $this->request);
        $exchange->setDefaulFormat($this->getDefaultFormat());
        foreach ($this as $key => $v) {
            $exchange->loadCurrency($key, $v->getFormat());
        }

        $exchange->tax = $this->tax;

        return self::$history[$key] = $exchange;
    }

// </editor-fold>
}
