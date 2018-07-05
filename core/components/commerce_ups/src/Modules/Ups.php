<?php
namespace PoconoSewVac\Ups\Modules;
use modmore\Commerce\Modules\BaseModule;
use modmore\Commerce\Admin\Widgets\Form\DescriptionField;
use modmore\Commerce\Admin\Widgets\Form\PasswordField;
use modmore\Commerce\Admin\Widgets\Form\TextField;
use Symfony\Component\EventDispatcher\EventDispatcher;

require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

class Ups extends BaseModule {

    public function getName()
    {
        $this->adapter->loadLexicon('commerce_ups:default');
        return $this->adapter->lexicon('commerce_ups');
    }

    public function getAuthor()
    {
        return 'Tony Klapatch';
    }

    public function getDescription()
    {
        return $this->adapter->lexicon('commerce_ups.description');
    }

    public function initialize(EventDispatcher $dispatcher)
    {
        // Load our lexicon
        $this->adapter->loadLexicon('commerce_ups:default');

        /*
            Dispatch clear rate cache on delete/add/update cart item.
            Required to cache UPS result while updating it when needed.
        */
        $dispatcher->addListener(\Commerce::EVENT_ORDERITEM_ADDED, array($this, 'clearRateCache'));
        $dispatcher->addListener(\Commerce::EVENT_ORDERITEM_UPDATED, array($this, 'clearRateCache'));
        $dispatcher->addListener(\Commerce::EVENT_ORDERITEM_REMOVED, array($this, 'clearRateCache'));

        // Add the xPDO package, so Commerce can detect the derivative classes
        $root = dirname(dirname(__DIR__));
        $path = $root . '/model/';
        $this->adapter->loadPackage('commerce_ups', $path);
    }

    public function clearRateCache($orderItem) {
        $order = $orderItem->getOrder();

        $pool = $this->adapter->getCacheService('commerce_ups');
        $rateCache = $pool->getItem('commerce_ups-rate' . $order->get('id'));

        if ($rateCache->isHit()) {
            $pool->deleteItem('commerce_ups-rate' . $order->get('id'));
        }
    }

    public function getModuleConfiguration(\comModule $module)
    {
        $fields = [];

        $fields[] = new DescriptionField($this->commerce, [
            'description' => $this->adapter->lexicon('commerce_ups.module_description'),
        ]);

        return $fields;
    }
}
