<?php
use modmore\Commerce\Admin\Widgets\Form\TextField;
use modmore\Commerce\Admin\Widgets\Form\PasswordField;
use modmore\Commerce\Admin\Widgets\Form\SelectField;
use modmore\Commerce\Admin\Widgets\Form\Validation\Required;
use modmore\Commerce\Admin\Widgets\Form\Validation\Regex;
use PhpUnitsOfMeasure\PhysicalQuantity\Mass;

/**
 * UPS for Commerce.
 *
 * Copyright 2018 by Tony Klapatch <tony@klapatch.net>
 *
 * This file is meant to be used with Commerce by modmore. A valid Commerce license is required.
 *
 * @package commerce_ups
 * @license See core/components/commerce_ups/docs/license.txt
 */
class UpsShippingMethod extends comShippingMethod {

    /**
     * Fetches the price to display in Commerce methods.
     *
     * @param comOrderShipment $shipment
     * @return int
     */
    public function getPriceForShipment(comOrderShipment $shipment)
    {
        $order = $shipment->getOrder();
        $rates = $this->getRates($order, $shipment);

        if (!$rates) {
            $this->isAvailableForShipment = false;
            $this->clearRateCache($order);
            return parent::getPriceForShipment($shipment);
        }

        foreach ($rates->RatedShipment as $ratedShipment) {
            if ($ratedShipment->Service->getCode() === $this->getProperty('service')) {
                foreach ($ratedShipment->RatedPackage as $ratedPackage) {
                    $price += floor($ratedPackage->TotalCharges->MonetaryValue * 100);
                }
                break;
            }
        }
        
        return parent::getPriceForShipment($shipment) + $price;
    }

    /**
     * Verifies that the order can be shipped with the selected UPS shipping method.
     *
     * @param comOrderShipment $shipment
     * @return boolean
     */
    public function isAvailableForShipment(comOrderShipment $shipment) {
        // Required to run first in determining if shipment is available.
        if ($this->getRates($shipment->getOrder(), $shipment)) {
            return parent::isAvailableForShipment($shipment);
        }

        return false;
    }

    /**
     * Gets the rates and calls UPS if not cached.
     *
     * @param comOrder $order
     * @param comOrderShipment $shipment
     * @return void
     */
    public function getRates(comOrder $order, comOrderShipment $shipment, $cache = true) {
        $pool = $this->adapter->getCacheService('commerce_ups');
        $rateCache = $pool->getItem('commerce_ups-rate' . $order->get('id'));

        if ($rateCache->isHit() && $cache) {
            $rates = $rateCache->get();
        } else {
            $rates = $this->__getRates($order, $shipment);
            $rateCache->set($rates)->expiresAfter(3600); // 60 minutes

            if (!$pool->save($rateCache)) {
                $this->adapter->log(1, '[commerce_ups] An error occured saving rates to cache for order ' . $order->get('id'));
                return false;
            }
        }

        return $rates;
    }

    /**
     * Calls the UPS API to retreive rates. Use getRates to prevent unneeded calls to the API.
     *
     * @param comOrder $order
     * @param comOrderShipment $orderShipment
     * @return Array
     */
    private function __getRates(comOrder $order, comOrderShipment $orderShipment) {
        $rate = new \Ups\RateTimeInTransit(
            $this->adapter->getOption('commerce_ups.api_key'),
            $this->adapter->getOption('commerce_ups.user'),
            $this->adapter->getOption('commerce_ups.password')
        );

        try {
            $shipment = new \Ups\Entity\Shipment();
            $customerAddress = $order->getShippingAddress();

            $shipperAddress = $shipment->getShipper()->getAddress();
            $shipperAddress->setPostalCode($this->getProperty('zip'));

            $address = new \Ups\Entity\Address();
            $address->setPostalCode($this->getProperty('zip'));
            $shipFrom = new \Ups\Entity\ShipFrom();
            $shipFrom->setAddress($address);

            $shipment->setShipFrom($shipFrom);

            $shipTo = $shipment->getShipTo();
            if ($customerAddress->get('company')) {
                $shipTo->setCompanyName($customerAddress->get('company'));
            } else {
                $shipTo->setAttentionName($customerAddress->get('fullname'));
            }
            $shipToAddress = $shipTo->getAddress();
            $shipToAddress->setPostalCode($customerAddress->get('zip'));

            $shipment = $this->getPackages($shipment, $order, $orderShipment);
            if (!$shipment) {
                return false;
            }

            $deliveryTimeInformation = new \Ups\Entity\DeliveryTimeInformation();
            $deliveryTimeInformation->setPackageBillType(\Ups\Entity\DeliveryTimeInformation::PBT_NON_DOCUMENT);

            $pickup = new \Ups\Entity\Pickup();
            $date = new DateTime($this->getProperty('pickup_time'));
            $pickup->setDate($date->format('Ymd'));
            $pickup->setTime($date->format('His'));
            $shipment->setDeliveryTimeInformation($deliveryTimeInformation);

            return $rate->shopRatesTimeInTransit($shipment);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Gets the packages inside this order.
     *
     * @param Shipment $shipment
     * @param comOrder $order
     * @param comOrderShipment $orderShipment
     * @return Shipment Revised shipment object.
     */
    public function getPackages($shipment, $order, $orderShipment) {
        /*
            Enables the usage of a custom snippet calculation method.
            Passes relevant order information for calculation.
        */
        if ($this->getProperty('package_calculation')) {
            return $this->adapter->runSnippet($this->getProperty('package_calculation'), [
                'shipment' => $shipment,
                'order' => $order,
                'orderShipment' => $orderShipment
            ]);
        }

        // UPS requires weight in pounds
        $shipmentWeight = $orderShipment->getWeight();
        $weight = $shipmentWeight->toUnit('lb');
        if ($weight == 0) {
            return false;
        }

        $package = new \Ups\Entity\Package();
        $package->getPackagingType()->setCode(\Ups\Entity\PackagingType::PT_PACKAGE);
        $package->getPackageWeight()->setWeight($weight);

        $weightUnit = new \Ups\Entity\UnitOfMeasurement;
        $weightUnit->setCode(\Ups\Entity\UnitofMeasurement::UOM_LBS);
        $package->getPackageWeight()->setUnitOfMeasurement($weightUnit);

        $dimensions = new \Ups\Entity\Dimensions();
        // @todo custom dimensions
        $dimensions->setHeight(10);
        $dimensions->setWidth(10);
        $dimensions->setLength(10);

        $unit = new \Ups\Entity\UnitOfMeasurement;
        $unit->setCode(\Ups\Entity\UnitOfMeasurement::UOM_IN);

        $dimensions->setUnitOfMeasurement($unit);
        $package->setDimensions($dimensions);

        $shipment->addPackage($package);

        return $shipment;
    }

    /**
     * Clears the cached rate from the UPS API call.
     *
     * @param comOrder $order
     * @return void
     */
    public function clearRateCache($order) {
        $pool = $this->adapter->getCacheService('commerce_ups');
        $rateCache = $pool->getItem('commerce_ups-rate' . $order->get('id'));

        if ($rateCache->isHit()) {
            $pool->deleteItem('commerce_ups-rate' . $order->get('id'));
        }
    }

    /**
     * Gets the services provided by the UPS API into a label, value list to display in Commerce.
     *
     * @return Array
     */
    public function getServices() {
        $service = new \Ups\Entity\Service();

        foreach ($service->getServices() as $value => $label) {
            $services[] = [
                'value' => $value,
                'label' => $label
            ]; 
        }

        return $services;
    }

    public function getModelFields() {
        $fields = parent::getModelFields();

        $fields[] = new SelectField($this->commerce, [
            'label' => 'UPS Service',
            'description' => 'Not all UPS services are available for all accounts.',
            'name' => 'properties[service]',
            'value' => $this->getProperty('service'),
            'options' => $this->getServices(),
            'validation' => [
                new Required()
            ]
        ]);
        $fields[] = new TextField($this->commerce, [
            'label' => 'Custom Package Calculation',
            'description' => 'Optional custom snippet to use for customizing how packages, weights, and lengths are added to what gets sent to UPS.',
            'name' => 'properties[package_calculation]',
            'value' => $this->getProperty('package_calculation')
        ]);
        $fields[] = new TextField($this->commerce, [
            'label' => 'Pickup Time',
            'description' => 'Approximate time during the day for UPS pickup.',
            'name' => 'properties[pickup_time]',
            'value' => $this->getProperty('pickup_time'),
            'validation' => [
                new Required(),
                new Regex('/^([01]\d|2[0-3]):?([0-5]\d)$/', 'Incorrect time. Requires 24 hour HH:MM format.')
            ]
        ]);
        $fields[] = new TextField($this->commerce, [
            'label' => 'Ship From Zipcode',
            'description' => 'The zip code you will be shipping items from.',
            'name' => 'properties[zip]',
            'value' => $this->getProperty('zip'),
            'validation' => [
                new Required()
            ]
        ]);

        return $fields;
    }
}
