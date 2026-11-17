<?php

namespace Adwise\Analytics\Test\Unit;

use Adwise\Analytics\Service\AnalyticsCaller;
use Adwise\Analytics\Helper\Data as DataHelper;
use Magento\Sales\Model\Order as MagentoOrder;
use Adwise\Analytics\Service\AnalyticsService;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Magento\Sales\Model\Order\Creditmemo;


class AnalyticsServiceTest extends TestCase
{

    /**
     * @var AnalyticsCaller|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $analyticsCallerMock;

    /** @var OrderRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject */
    protected $orderRepositoryMock;

    /**
     * @var DataHelper|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $datahelperMock;

    /**
     * @var AnalyticsService
     */
    protected $analyticsService;

    protected function setUp(): void
    {
        $this->analyticsCallerMock = $this->getMockBuilder(AnalyticsCaller::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->orderRepositoryMock = $this->createMock(OrderRepositoryInterface::class);
        $this->datahelperMock = $this->createStub(DataHelper::class);

        $this->analyticsService = new AnalyticsService(
            $this->createStub(LoggerInterface::class),
            $this->datahelperMock,
            $this->analyticsCallerMock,
            $this->orderRepositoryMock
        );
    }

    /**
     * Configures dataHelper mock to imitate specific config settings
     * @param $enableModule
     * @param $enableOrderHit
     * @param $enableCreditMemoHit
     */
    private function enableSettings($enableModule, $enableOrderHit, $enableCreditMemoHit){
        $this->datahelperMock->method('getIsEnabled')->willReturn($enableModule);
        $this->datahelperMock->method('getMPEnabled')->willReturn($enableModule);
        $this->datahelperMock->method('getAnyEnabled')->willReturn($enableModule);
        $this->datahelperMock->method('getAnyPurchaseEventEnabled')->willReturn($enableOrderHit);
        $this->datahelperMock->method('getAnyRefundEventEnabled')->willReturn($enableCreditMemoHit);
        $this->datahelperMock->method('getMPPurchaseEventEnabled')->willReturn($enableOrderHit);
        $this->datahelperMock->method('getMPRefundEventEnabled')->willReturn($enableCreditMemoHit);
    }

    /**
     *  Test if a PENDING_PAYMENT is not exported to Google
     */
    public function testPendingOrderNotProcessed(): void
    {
        $this->orderRepositoryMock->expects($this->never())->method('save');
        $this->enableSettings(true,true,true);
        $order = $this->getMockedOrderWithState(MagentoOrder::STATE_PENDING_PAYMENT);
        $order->expects($this->never())->method('setAwAnalyticsExport');

        $this->analyticsCallerMock->expects($this->never())->method('handleOrder');
        $this->assertEquals(AnalyticsService::ERROR_UNKNOWN_ORDER_STATE, $this->analyticsService->handleOrder($order));
    }

    /**
     * Test if a processing order, that has never been exported before, is successfully exported
     */
    public function testProcessingOrderNeverExported(): void
    {
        $this->enableSettings(true,true,true);
        $order = $this->getMockedOrderWithState(MagentoOrder::STATE_PROCESSING);

        //This is the first time we have exported this order
        $order->expects($this->atLeastOnce())->method('getAwAnalyticsExport')->willReturn(null);
        $order->expects($this->once())->method('setAwAnalyticsExport')->with(AnalyticsService::EXPORT_STATUS_PROCESSED);
        $order->expects($this->once())->method('addCommentToStatusHistory');
        $this->orderRepositoryMock->expects($this->once())->method('save')->with($order);

        $this->analyticsCallerMock->expects($this->once())->method('handleOrder')->with($order);
        $this->assertEquals(AnalyticsService::SUCCESS, $this->analyticsService->handleOrder($order));
    }

    /**
     * A failing Google Analytics call must not break the order save and must leave the order exportable
     */
    public function testProcessingOrderAnalyticsFailureIsContained(): void
    {
        $this->enableSettings(true,true,true);
        $order = $this->getMockedOrderWithState(MagentoOrder::STATE_PROCESSING);

        $order->expects($this->atLeastOnce())->method('getAwAnalyticsExport')->willReturn(null);
        $order->expects($this->never())->method('setAwAnalyticsExport');
        $order->expects($this->never())->method('setAwAnalyticsAmount');
        $this->orderRepositoryMock->expects($this->never())->method('save');

        $this->analyticsCallerMock->expects($this->once())->method('handleOrder')
            ->willThrowException(new \Exception('Operation timed out'));

        $this->assertEquals(AnalyticsService::ERROR_EXPORT_FAILED, $this->analyticsService->handleOrder($order));
    }

    /**
     * Test if other states are never exported
     */
    public function testInvalidProcessingStates(){
        $this->orderRepositoryMock->expects($this->never())->method('save');
        $invalidStates = [
            AnalyticsService::EXPORT_STATUS_OLD_EXPORTED_POSITIVE,
            AnalyticsService::EXPORT_STATUS_OLD_EXPORTED_NEGATIVE,
            AnalyticsService::EXPORT_STATUS_PROCESSED,
            AnalyticsService::EXPORT_STATUS_NEW_CANCELLED,
            AnalyticsService::EXPORT_STATUS_FULLY_REFUNDED,
        ];

        $this->enableSettings(true,true,true);

        $this->analyticsCallerMock->expects($this->never())->method('handleOrder');

        foreach($invalidStates as $state){
            $order = $this->getMockedOrderWithState(MagentoOrder::STATE_PROCESSING);
            $order->expects($this->atLeastOnce())->method('getAwAnalyticsExport')->willReturn($state);
            $this->assertNotEquals(AnalyticsService::SUCCESS, $this->analyticsService->handleOrder($order));
        }
    }

    /**
     * Test a credit memo for the full price of the order
     */
    public function testCreditMemoRefundFull() {
        $this->enableSettings(true,true,true);
        $creditMemo = $this->getMockedCreditMemo();
        $order = $this->getMockedOrderWithState(MagentoOrder::STATE_COMPLETE);

        // Validate we actually send
        $this->analyticsCallerMock->expects($this->once())->method('handleCreditMemo');

        //Order has been exported
        $order->expects($this->once())->method('getAwAnalyticsExport')->willReturn(AnalyticsService::EXPORT_STATUS_PROCESSED);
        //Order has exported for 100$
        $order->expects($this->atLeastOnce())->method('getAwAnalyticsAmount')->willReturn(100);
        // we are refunding 100
        $creditMemo->expects($this->atLeastOnce())->method('getGrandTotal')->willReturn(100);
        // So we expect 0 to be saved
        $order->expects($this->once())->method('setAwAnalyticsAmount')->with(0);
        $order->expects($this->once())->method('setAwAnalyticsExport')->with(AnalyticsService::EXPORT_STATUS_FULLY_REFUNDED);
        $this->orderRepositoryMock->expects($this->once())->method('save')->with($order);
        $creditMemo->method('getOrder')->willReturn($order);

        $this->assertEquals(AnalyticsService::SUCCESS, $this->analyticsService->handleCreditmemo($creditMemo));
    }

    /**
     * Test partial refund
     */
    public function testCreditMemoRefundPartial() {
        $this->enableSettings(true,true,true);
        $creditMemo = $this->getMockedCreditMemo();
        $order = $this->getMockedOrderWithState(MagentoOrder::STATE_COMPLETE);

        // Validate we actually send
        $this->analyticsCallerMock->expects($this->once())->method('handleCreditMemo');

        //Order has been exported
        $order->expects($this->once())->method('getAwAnalyticsExport')->willReturn(AnalyticsService::EXPORT_STATUS_PROCESSED);
        //Order has exported for 100$
        $order->expects($this->atLeastOnce())->method('getAwAnalyticsAmount')->willReturn(100);
        // we are refunding 50
        $creditMemo->expects($this->atLeastOnce())->method('getGrandTotal')->willReturn(50);
        // So we expect 50 to be saved
        $order->expects($this->once())->method('setAwAnalyticsAmount')->with(50);
        $this->orderRepositoryMock->expects($this->once())->method('save')->with($order);
        $creditMemo->method('getOrder')->willReturn($order);

        $this->assertEquals(AnalyticsService::SUCCESS, $this->analyticsService->handleCreditmemo($creditMemo));
    }

    /**
     * Don't export if we would export too much
     */
    public function testCreditMemoRefundTooMuch() {
        $this->orderRepositoryMock->expects($this->never())->method('save');
        $this->enableSettings(true,true,true);
        $creditMemo = $this->getMockedCreditMemo();
        $order = $this->getMockedOrderWithState(MagentoOrder::STATE_COMPLETE);

        // We should NEVER refund too much
        $this->analyticsCallerMock->expects($this->never())->method('handleCreditMemo');

        //Order has been exported
        $order->expects($this->once())->method('getAwAnalyticsExport')->willReturn(AnalyticsService::EXPORT_STATUS_PROCESSED);
        //Order has exported for 100$
        $order->expects($this->atLeastOnce())->method('getAwAnalyticsAmount')->willReturn(50);
        // we are refunding 50
        $creditMemo->expects($this->atLeastOnce())->method('getGrandTotal')->willReturn(100);
        $order->expects($this->never())->method('setAwAnalyticsAmount');
        $creditMemo->method('getOrder')->willReturn($order);

        $this->assertEquals(AnalyticsService::ERROR_CREDITMEMO_NEGATIVE_BALANCE, $this->analyticsService->handleCreditmemo($creditMemo));
    }

    /**
     * Test with module disabled
     */
    public function testDisablingModule(){
        $this->analyticsCallerMock->expects($this->never())->method('handleOrder');
        $this->orderRepositoryMock->expects($this->never())->method('save');
        $this->enableSettings(false,true,true);
        $order = $this->getMockedOrderWithState(MagentoOrder::STATE_PROCESSING);
        $creditMemo = $this->getMockedCreditMemo();
        $order->expects($this->never())->method('setAwAnalyticsExport');

        $this->assertEquals(AnalyticsService::ERROR_DISABLED, $this->analyticsService->handleOrder($order));
        $this->assertEquals(AnalyticsService::ERROR_DISABLED, $this->analyticsService->handleCreditmemo($creditMemo));
    }

    /**
     * Test if order hit is disabled
     */
    public function testDisablingOrderHit(){
        $this->analyticsCallerMock->expects($this->never())->method('handleOrder');
        $this->enableSettings(true,false,true);
        $order = $this->getMockedOrderWithState(MagentoOrder::STATE_PROCESSING);

        $this->orderRepositoryMock->expects($this->once())->method('save')->with($order);
        $order->expects($this->once())->method('setAwAnalyticsExport')
            ->with(AnalyticsService::EXPORT_STATUS_ORDER_HIT_DISABLED);

        $this->assertEquals(AnalyticsService::ERROR_DISABLED, $this->analyticsService->handleOrder($order));
    }

    /**
     * Test if credit memo hit is disabled
     */
    public function testDisablingCreditMemo(){
        $this->analyticsCallerMock->expects($this->never())->method('handleOrder');
        $this->orderRepositoryMock->expects($this->never())->method('save');
        $this->enableSettings(true,true,false);
        $creditMemo = $this->getMockedCreditMemo();

        $this->assertEquals(AnalyticsService::ERROR_DISABLED, $this->analyticsService->handleCreditmemo($creditMemo));
    }

    /**
     * Test if we can handle creditMemo if order hits are disabled
     */
    public function testCreditMemoWithDisabledOrderHits() {
        $this->analyticsCallerMock->expects($this->never())->method('handleOrder');
        $this->enableSettings(true, false, true);
        $order = $this->getMockedOrderWithState(MagentoOrder::STATE_PROCESSING);

        $order->expects($this->once())->method('getGrandTotal')->willReturn(100);
        $amounts = [];
        $states = [];
        $order->expects($this->exactly(2))->method('setAwAnalyticsAmount')
            ->willReturnCallback(static function ($amount) use (&$amounts) {
                $amounts[] = $amount;
            });
        $order->expects($this->exactly(2))->method('setAwAnalyticsExport')
            ->willReturnCallback(static function ($state) use (&$states) {
                $states[] = $state;
            });
        $this->orderRepositoryMock->expects($this->exactly(2))->method('save')->with($order);


        $this->assertEquals(AnalyticsService::ERROR_DISABLED, $this->analyticsService->handleOrder($order));

        $order->method('getAwAnalyticsAmount')->willReturn(100);
        $order->method('getAwAnalyticsExport')->willReturn(AnalyticsService::EXPORT_STATUS_ORDER_HIT_DISABLED);

        // Try creating a creditMemo
        $creditMemo = $this->getMockedCreditMemo();
        $creditMemo->method('getOrder')->willReturn($order);
        $creditMemo->method('getGrandTotal')->willReturn(100);

        $this->assertEquals(AnalyticsService::SUCCESS, $this->analyticsService->handleCreditmemo($creditMemo));
        $this->assertSame([100, 0.0], $amounts);
        $this->assertSame([
            AnalyticsService::EXPORT_STATUS_ORDER_HIT_DISABLED,
            AnalyticsService::EXPORT_STATUS_FULLY_REFUNDED,
        ], $states);
    }

    /**
     * Get mocked order with specific state
     *
     * @param $state
     * @return MagentoOrder
     */
    private function getMockedOrderWithState($state){
        $order = $this->getMockBuilder(AnalyticsOrder::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getState', 'addCommentToStatusHistory', 'getGrandTotal', 'getStoreId', 'getIncrementId',
                'getAwAnalyticsExport', 'setAwAnalyticsExport', 'getAwAnalyticsAmount', 'setAwAnalyticsAmount'
            ])
            ->getMock();
        $order->method('getState')->willReturn($state);
        return $order;
    }

    /**
     * Get a credit memo mock
     * @return Creditmemo
     */
    private function getMockedCreditMemo() {
        $creditMemo = $this->getMockBuilder(Creditmemo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStoreId', 'getOrder', 'getGrandTotal', 'getIncrementId'])
            ->getMock();
        $creditMemo->expects($this->atLeastOnce())->method('getStoreId');
        return $creditMemo;
    }
}
