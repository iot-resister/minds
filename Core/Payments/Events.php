<?php
namespace Minds\Core\Payments;

use Minds\Core;
use Minds\Core\Di\Di;
use Minds\Core\Email\Campaigns;
use Minds\Core\Events\Dispatcher;
use Minds\Core\Payments;
use Minds\Core\Session;

/**
 * Minds Payments Events
 */
class Events
{
    public function register()
    {
        Dispatcher::register('export:extender', 'activity', function($event) {
            $params = $event->getParameters();
            $activity = $params['entity'];
            if($activity->type != 'activity'){
                return;
            }
            $export = $event->response() ?: [];
            $currentUser = Session::getLoggedInUserGuid();

            $dirty = false;

            if ($activity->isPaywall() && $activity->owner_guid != $currentUser) {
                $export['message'] = null;
                $export['custom_type'] = null;
                $export['custom_data'] = null;
                $export['thumbnail_src'] = null;
                $export['perma_url'] = null;
                $export['blurb'] = null;
                $export['title'] = null;

                $dirty = true;
            }

            if (
                $activity->remind_object &&
                (int) $activity->remind_object['paywall'] &&
                $activity->remind_object['owner_guid'] != $currentUser
            ) {
                $export['remind_object'] = $activity->remind_object;
                $export['remind_object']['message'] = null;
                $export['remind_object']['custom_type'] = null;
                $export['remind_object']['custom_data'] = null;
                $export['remind_object']['thumbnail_src'] = null;
                $export['remind_object']['perma_url'] = null;
                $export['remind_object']['blurb'] = null;
                $export['remind_object']['title'] = null;

                $dirty = true;
            }

            if ($dirty) {
                return $event->setResponse($export);
            }

            if (!$currentUser) {
                return;
            }

        });

        Dispatcher::register('export:extender', 'blog', function($event) {
            $params = $event->getParameters();
            $blog = $params['entity'];
            if($blog->subtype != 'blog'){
                return;
            }
            $export = $event->response() ?: [];
            $currentUser = Session::getLoggedInUserGuid();

            $dirty = false;

            if ($blog->isPaywall() && $blog->owner_guid != $currentUser) {
                $export['description'] = null;
                $dirty = true;
            }

            if ($dirty) {
                return $event->setResponse($export);
            }

            if (!$currentUser) {
                return;
            }

        });

        Dispatcher::register('acl:read', 'object', function($event) {
            $params = $event->getParameters();
            $entity = $params['entity'];
            $user = $params['user'];

            if (!method_exists($entity, 'getFlag') || !$entity->getFlag('paywall')) {
                return;
            }

            if (!$user) {
                return false;
            }

            //Plus hack
            if ($entity->owner_guid == '730071191229833224') {
                $plus = (new Core\Plus\Subscription())->setUser($user);

                if ($plus->isActive()) {
                    return $event->setResponse(true);
                }
            }

            try {
                $isAllowed = Di::_()->get('Wire\Thresholds')->isAllowed($user, $entity);
            } catch (\Exception $e) { }

            if ($isAllowed) {
                return $event->setResponse(true);
            }

            /** @var Subscriptions\Manager $manager */
            $manager = Di::_()->get('Payments\Subscriptions\Manager');

            $manager
                ->setUserGuid($user->guid)
                ->setEntityGuid($entity->owner_guid)
                ->setType('exclusive')
                ->setPaymentMethod('money');

            $exclusiveSubscription = $manager->fetchSubscription([ 'hydrate' => false ]);

            if ($exclusiveSubscription) {
                return $event->setResponse($exclusiveSubscription['status'] == 'active');
            }

            return $event->setResponse(false);
        });

        Dispatcher::register('wire-payment-email', 'object', function ($event) {
            $campaign = new Campaigns\WirePayment;
            $params = $event->getParameters();
            $user = $params['user'];
            if (!$user) {
                return false;
            }

            $campaign->setUser($user);

            if ($params['charged']) {
                $bankAccount = $params['bankAccount'];
                $dateOfDispatch = $params['dateOfDispatch'];
                if (!$bankAccount || !$dateOfDispatch) {
                    return false;
                }
                $campaign->setBankAccount($bankAccount)
                    ->setDateOfDispatch($dateOfDispatch);
            } else {
                $amount = $params['amount'];
                $unit = $params['unit'];
                if (!$amount || !$unit) {
                    return false;
                }

                $campaign->setAmount($amount)
                    ->setDescription($unit);
            }

            $campaign->send();


            return $event->setResponse(true);
        });
    }
}
