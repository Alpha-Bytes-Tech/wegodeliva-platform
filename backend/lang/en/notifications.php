<?php

// Notification texts by key (spec 08). Placeholders like :code are filled per message.
// Payloads carry only ids and short text: no addresses or phone numbers.
return [
    'customer' => [
        'order_placed' => ['title' => 'Order received', 'body' => ':vendor has your order :code.'],
        'order_scheduled' => ['title' => 'Order scheduled', 'body' => 'Order :code from :vendor is booked for :time.'],
        'order_accepted' => ['title' => 'Being prepared', 'body' => ':vendor is preparing order :code. Ready in about :minutes min.'],
        'order_ready_pickup' => ['title' => 'Ready for pickup', 'body' => 'Order :code is ready at :vendor. Show your handover code at the counter.'],
        'order_picked_up' => ['title' => 'On the way', 'body' => 'Your rider has order :code and is on the way.'],
        'order_delivered' => ['title' => 'Delivered', 'body' => 'Order :code has been delivered. Enjoy, and tell us how it went.'],
        'order_cancelled' => ['title' => 'Order cancelled', 'body' => 'Order :code was cancelled: :reason'],
        'order_failed' => ['title' => 'Delivery failed', 'body' => 'We could not deliver order :code. Our team will contact you.'],
        'rider_assigned' => ['title' => 'Rider assigned', 'body' => ':rider will deliver order :code.'],
        'still_finding_rider' => ['title' => 'Still finding a rider', 'body' => 'We are still looking for a rider for order :code. Thanks for your patience.'],
        'payment_failed' => ['title' => 'Payment failed', 'body' => 'The payment for order :code did not go through. You can try again.'],
        'refund_issued' => ['title' => 'Refund issued', 'body' => ':amount for order :code is now in your wallet.'],
        'topup_credited' => ['title' => 'Wallet topped up', 'body' => ':amount has been added to your wallet.'],
        'referral_reward' => ['title' => 'Referral reward', 'body' => 'You earned :amount because a friend you invited placed their first order.'],
    ],
    'vendor' => [
        'new_order' => ['title' => 'New order :code', 'body' => ':items item(s), :total. Open to accept.'],
        'accept_reminder' => ['title' => 'Order :code is waiting', 'body' => 'Please accept or reject it now.'],
        'order_cancelled' => ['title' => 'Order :code cancelled', 'body' => ':reason'],
        'rider_assigned' => ['title' => 'Rider coming', 'body' => ':rider is coming for order :code.'],
        'no_rider' => ['title' => 'Finding a rider', 'body' => 'We are still looking for a rider for order :code.'],
        'order_picked_up' => ['title' => 'Order :code picked up', 'body' => 'The rider has collected the order.'],
        'order_delivered' => ['title' => 'Order :code delivered', 'body' => 'Your earnings have been updated.'],
        'order_failed' => ['title' => 'Order :code not delivered', 'body' => 'The delivery failed. Our team will follow up.'],
        'document_requested' => ['title' => 'Document needed', 'body' => 'Please upload your :kind. :note'],
        'payout_sent' => ['title' => 'Payout on its way', 'body' => ':amount is being sent to your bank account.'],
        'payout_paid' => ['title' => 'Payout paid', 'body' => ':amount has reached your bank account.'],
        'payout_failed' => ['title' => 'Payout failed', 'body' => 'We could not send :amount. The money is back in your earnings.'],
        'payout_rejected' => ['title' => 'Payout declined', 'body' => 'Your payout of :amount was declined: :reason'],
    ],
    'rider' => [
        'job_assigned' => ['title' => 'New job :code', 'body' => 'Pick up from :vendor.'],
        'order_ready' => ['title' => 'Ready for pickup', 'body' => 'Order :code is ready at :vendor.'],
        'job_cancelled' => ['title' => 'Job :code cancelled', 'body' => 'You do not need to deliver this order.'],
        'job_delivered' => ['title' => 'Job :code done', 'body' => 'Your earnings have been updated.'],
        'document_requested' => ['title' => 'Document needed', 'body' => 'Please upload your :kind. :note'],
        'payout_sent' => ['title' => 'Payout on its way', 'body' => ':amount is being sent to your bank account.'],
        'payout_paid' => ['title' => 'Payout paid', 'body' => ':amount has reached your bank account.'],
        'payout_failed' => ['title' => 'Payout failed', 'body' => 'We could not send :amount. The money is back in your earnings.'],
        'payout_rejected' => ['title' => 'Payout declined', 'body' => 'Your payout of :amount was declined: :reason'],
    ],
];
