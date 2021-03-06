<?php

/*
 * This file has been created by developers from BitBag.
 * Feel free to contact us once you face any issues or want to start
 * another great project.
 * You can find more information about us on https://bitbag.shop and write us
 * an email on mikolaj.krol@bitbag.pl.
 */

declare(strict_types=1);

namespace BitBag\SyliusMolliePlugin\Action\Api;

use BitBag\SyliusMolliePlugin\Entity\SubscriptionInterface;
use BitBag\SyliusMolliePlugin\Request\Api\CreateRecurringSubscription;
use BitBag\SyliusMolliePlugin\Request\StateMachine\StatusRecurringSubscription;
use Doctrine\ORM\EntityManagerInterface;
use Mollie\Api\Resources\Customer;
use Mollie\Api\Resources\Subscription;
use Mollie\Api\Types\MandateMethod;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use SM\Factory\FactoryInterface as SateMachineFactoryInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;

final class CreateRecurringSubscriptionAction extends BaseApiAwareAction implements ActionInterface, GatewayAwareInterface, ApiAwareInterface
{
    use GatewayAwareTrait;

    /**
     * @var FactoryInterface
     */
    private $subscriptionFactory;

    /**
     * @var EntityManagerInterface
     */
    private $subscriptionManager;

    /**
     * @var SateMachineFactoryInterface
     */
    private $subscriptionSateMachineFactory;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @param FactoryInterface $subscriptionFactory
     * @param EntityManagerInterface $subscriptionManager
     * @param SateMachineFactoryInterface $subscriptionSateMachineFactory
     * @param OrderRepositoryInterface $orderRepository
     */
    public function __construct(
        FactoryInterface $subscriptionFactory,
        EntityManagerInterface $subscriptionManager,
        SateMachineFactoryInterface $subscriptionSateMachineFactory,
        OrderRepositoryInterface $orderRepository
    ) {
        $this->subscriptionFactory = $subscriptionFactory;
        $this->subscriptionManager = $subscriptionManager;
        $this->subscriptionSateMachineFactory = $subscriptionSateMachineFactory;
        $this->orderRepository = $orderRepository;
    }

    /**
     * {@inheritdoc}
     *
     * @param CreateRecurringSubscription $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());

        if (true === isset($model['subscription_id'])) {
            return;
        }

        /** @var Customer $customer */
        $customer = $this->mollieApiClient->customers->get($model['customer_mollie_id']);

        /** @var Subscription $subscriptionApiResult */
        $subscriptionApiResult = $customer->createSubscription([
            'amount' => $model['amount'],
            'interval' => $model['interval'],
            'description' => $model['description'],
            'method' => MandateMethod::DIRECTDEBIT,
            'webhookUrl' => $model['webhookUrl'],
        ]);

        /** @var SubscriptionInterface $subscription */
        $subscription = $this->subscriptionFactory->createNew();

        /** @var OrderInterface $order */
        $order = $this->orderRepository->find($model['metadata']['order_id']);

        $subscription->setSubscriptionId($subscriptionApiResult->id);
        $subscription->setCustomerId($model['customer_mollie_id']);
        $subscription->setOrder($order);

        $this->subscriptionManager->persist($subscription);

        $model['subscription_mollie_id'] = $subscriptionApiResult->id;

        $this->gateway->execute(new StatusRecurringSubscription($subscription));
    }

    /**
     * {@inheritdoc}
     */
    public function supports($request): bool
    {
        return
            $request instanceof CreateRecurringSubscription &&
            $request->getModel() instanceof \ArrayAccess
        ;
    }
}
