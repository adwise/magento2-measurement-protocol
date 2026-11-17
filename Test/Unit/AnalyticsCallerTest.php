<?php

namespace Adwise\Analytics\Test\Unit;

use Adwise\Analytics\Api\MeasurementProtocol\ClientIdProviderInterface;
use Adwise\Analytics\Helper\Data;
use Adwise\Analytics\Model\CreditMemoDataProviders as CreditMemoDataProvidersUA;
use Adwise\Analytics\Model\DataProviders\MP\TimestampProvider;
use Adwise\Analytics\Model\MP\CreditMemoDataProviders as CreditMemoDataProvidersMP;
use Adwise\Analytics\Model\MP\OrderDataProviders as OrderDataProvidersMP;
use Adwise\Analytics\Model\MP\UserPropertyDataProviders;
use Adwise\Analytics\Model\OrderDataProviders as OrderDataProvidersUA;
use Adwise\Analytics\Service\AnalyticsCaller;
use GuzzleHttp\ClientFactory;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use stdClass;

class AnalyticsCallerTest extends TestCase
{
    public function testDisabledEventsDoNotSubmitPayloads(): void
    {
        $dataHelper = $this->createStub(Data::class);
        $dataHelper->method('getMPPurchaseEventEnabled')->willReturn(false);
        $dataHelper->method('getUAPurchaseEventEnabled')->willReturn(false);
        $dataHelper->method('getMPRefundEventEnabled')->willReturn(false);
        $dataHelper->method('getUARefundEventEnabled')->willReturn(false);

        $caller = $this->getMockBuilder(AnalyticsCaller::class)
            ->setConstructorArgs([
                $dataHelper,
                new OrderDataProvidersMP([]),
                new CreditMemoDataProvidersMP([]),
                new OrderDataProvidersUA([]),
                new CreditMemoDataProvidersUA([]),
                $this->createStub(TimestampProvider::class),
                $this->createStub(ClientIdProviderInterface::class),
                new UserPropertyDataProviders([]),
                $this->createStub(LoggerInterface::class),
                $this->createStub(ClientFactory::class),
            ])
            ->onlyMethods(['submitToGoogleMP', 'submitToGoogleUA'])
            ->getMock();
        $caller->expects($this->never())->method('submitToGoogleMP');
        $caller->expects($this->never())->method('submitToGoogleUA');

        $caller->handleOrder(new stdClass());
        $caller->handleCreditMemo(new stdClass());
    }
}
