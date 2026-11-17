<?php

namespace Adwise\Analytics\Test\Unit;

use Magento\Sales\Model\Order as MagentoOrder;

abstract class AnalyticsOrder extends MagentoOrder
{
    abstract public function getAwAnalyticsExport();

    abstract public function setAwAnalyticsExport($state);

    abstract public function getAwAnalyticsAmount();

    abstract public function setAwAnalyticsAmount($amount);
}
