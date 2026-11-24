<?php

use App\Enums\ClientApp;
use App\Enums\EarningsOwner;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Enums\WalletKind;
use App\Mail\OtpCodeMail;
use App\Models\AdminAuditLog;
use App\Models\EarningsAccount;
use App\Models\Order;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Ledger\WalletLedger;
use Database\Seeders\CitySeeder;
use Database\Seeders\VendorTypeSeeder;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Checkout;
use Tests\Support\FakeStorage;

beforeEach(function () {
    $this->seed([VendorTypeSeeder::class, CitySeeder::class]);
    Mail::fake();
    $this->user = makeUser();
});

function me(User $user, ClientApp $app = ClientApp::Customer)
{
    app('auth')->forgetGuards();

    return test()->withToken(tokenFor($user, $app));
}

function codeSentTo(string $email): string
{
    $code = '';
    Mail::assertQueued(OtpCodeMail::class, function (OtpCodeMail $mail) use ($email, &$code) {
        if ($mail->hasTo($email)) {
            $code = $mail->code;
        }

        return true;
    });

    return $code;
}

function ownPath(User $user, string $folder = 'avatars', string $ext = 'jpg'): string
{
    return "{$folder}/{$user->uuid}/01jbxk7m2r3s4t5v6w7x8y9z0a.{$ext}";
}

it('hands out signed upload URLs in the user\'s own folder', function () {
    expectApiError(me($this->user)->postJson('/api/v1/uploads', ['kind' => 'avatar', 'content_type' => 'image/jpeg']), 503, 'uploads_unavailable');

    FakeStorage::fake();
    $data = me($this->user)->postJson('/api/v1/uploads', ['kind' => 'prescription', 'content_type' => 'application/pdf'])->assertCreated()->json('data');
    expect($data['path'])->toMatch('#^prescriptions/'.$this->user->uuid.'/[0-9a-z]{26}\.pdf$#')
        ->and($data['upload_url'])->toBe(FakeStorage::URL.'/storage/v1/object/upload/sign/uploads/'.$data['path'].'?token=up-123')
        ->and($data['token'])->toBe('up-123');

    expectApiError(me($this->user)->postJson('/api/v1/uploads', ['kind' => 'avatar', 'content_type' => 'application/pdf']), 400, 'validation_failed');
    expectApiError(me($this->user)->postJson('/api/v1/uploads', ['kind' => 'video', 'content_type' => 'video/mp4']), 400, 'validation_failed');

    FakeStorage::use(FakeStorage::BROKEN);
    expectApiError(me($this->user)->postJson('/api/v1/uploads', ['kind' => 'avatar', 'content_type' => 'image/png']), 503, 'uploads_unavailable');
    FakeStorage::use(FakeStorage::DOWN);
    expectApiError(me($this->user)->postJson('/api/v1/uploads', ['kind' => 'avatar', 'content_type' => 'image/png']), 503, 'uploads_unavailable');
});

it('updates the profile; a new phone needs the password when there is one', function () {
    FakeStorage::fake();
    me($this->user)->patchJson('/api/v1/auth/me', ['name' => 'Ada Obi', 'avatar_path' => ownPath($this->user)])->assertOk()
        ->assertJsonPath('data.name', 'Ada Obi')
        ->assertJsonPath('data.avatar_url', FakeStorage::readUrl(ownPath($this->user)));

    $other = makeUser();
    expectApiError(me($this->user)->patchJson('/api/v1/auth/me', ['avatar_path' => ownPath($other)]), 400, 'validation_failed');
    expectApiError(me($this->user)->patchJson('/api/v1/auth/me', ['phone' => '08099990000']), 422, 'password_incorrect');
    expectApiError(me($this->user)->patchJson('/api/v1/auth/me', ['phone' => '08099990000', 'current_password' => 'wrong']), 422, 'password_incorrect');
    expectApiError(me($this->user)->patchJson('/api/v1/auth/me', ['phone' => $other->phone, 'current_password' => 'password123']), 400, 'validation_failed');
    me($this->user)->patchJson('/api/v1/auth/me', ['phone' => '0809 999 0000', 'current_password' => 'password123'])->assertOk()
        ->assertJsonPath('data.phone', '+2348099990000');

    $social = makeUser();
    $social->forceFill(['password' => null])->save();
    me($social)->patchJson('/api/v1/auth/me', ['phone' => '08077770000'])->assertOk()->assertJsonPath('data.phone', '+2348077770000');
});

it('changes or sets the password and signs out other sessions', function () {
    $other = tokenFor($this->user, ClientApp::Customer);
    expectApiError(me($this->user)->postJson('/api/v1/auth/password', ['current_password' => 'wrong', 'password' => 'newpass123', 'password_confirmation' => 'newpass123']), 422, 'password_incorrect');
    me($this->user)->postJson('/api/v1/auth/password', ['current_password' => 'password123', 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])->assertOk();

    expect($this->user->tokens()->count())->toBe(1);
    app('auth')->forgetGuards();
    $this->withToken($other)->getJson('/api/v1/auth/me')->assertUnauthorized();

    $social = makeUser();
    $social->forceFill(['password' => null])->save();
    me($social)->postJson('/api/v1/auth/password', ['password' => 'first123pass', 'password_confirmation' => 'first123pass'])->assertOk()->assertJsonPath('data.has_password', true);
});

it('changes the email only after the code sent to the new address', function () {
    expectApiError(me($this->user)->postJson('/api/v1/auth/email/confirm', ['code' => '123456']), 422, 'otp_invalid');
    expectApiError(me($this->user)->postJson('/api/v1/auth/email/change', ['email' => $this->user->email]), 400, 'validation_failed');
    expectApiError(me($this->user)->postJson('/api/v1/auth/email/change', ['email' => makeUser()->email]), 409, 'email_taken');

    me($this->user)->postJson('/api/v1/auth/email/change', ['email' => 'New.Me@Example.com'])->assertStatus(202)->assertJsonPath('data.pending_email', 'new.me@example.com');
    me($this->user)->getJson('/api/v1/auth/me')->assertJsonPath('data.pending_email', 'new.me@example.com');
    $code = codeSentTo('new.me@example.com');

    expectApiError(me($this->user)->postJson('/api/v1/auth/email/confirm', ['code' => $code === '000000' ? '111111' : '000000']), 422, 'otp_invalid');
    me($this->user)->postJson('/api/v1/auth/email/confirm', ['code' => $code])->assertOk()
        ->assertJsonPath('data.email', 'new.me@example.com')
        ->assertJsonPath('data.pending_email', null)
        ->assertJsonPath('data.email_verified', true);
});

it('refuses the change if someone took the address while the code was out', function () {
    me($this->user)->postJson('/api/v1/auth/email/change', ['email' => 'race@example.com'])->assertStatus(202);
    $code = codeSentTo('race@example.com');
    User::factory()->create(['email' => 'race@example.com']);

    expectApiError(me($this->user)->postJson('/api/v1/auth/email/confirm', ['code' => $code]), 409, 'email_taken');
});

it('deletes an account after a code, unless something blocks it', function () {
    $wallets = app(WalletLedger::class);
    $wallets->credit($this->user, 5000, WalletKind::TopUp, 'w1');
    $error = expectApiError(me($this->user)->postJson('/api/v1/auth/account/delete-code'), 409, 'account_delete_blocked');
    expect($error['meta']['reasons'])->toBe(['wallet_balance']);
    $wallets->debit($this->user, 5000, WalletKind::Reversal, 'w2');

    $email = $this->user->email;
    me($this->user)->postJson('/api/v1/auth/account/delete-code')->assertStatus(202)->assertJsonPath('data.sent_to', $email);
    $code = codeSentTo($email);
    Checkout::address($this->user);

    expectApiError(me($this->user)->deleteJson('/api/v1/auth/account', ['code' => $code === '000000' ? '111111' : '000000']), 422, 'otp_invalid');
    me($this->user)->deleteJson('/api/v1/auth/account', ['code' => $code, 'reason' => 'Moving abroad'])->assertNoContent();

    $gone = User::withTrashed()->find($this->user->id);
    expect($gone->trashed())->toBeTrue()
        ->and($gone->status)->toBe(UserStatus::Deleted)
        ->and($gone->name)->toBe('Deleted user')
        ->and($gone->email)->toEndWith('@deleted.invalid')
        ->and($gone->tokens()->count())->toBe(0)
        ->and($gone->addresses()->count())->toBe(0)
        ->and(AdminAuditLog::query()->where('action', 'account.deleted')->exists())->toBeTrue();

    // The address can sign up again.
    $this->postJson('/api/v1/auth/register', ['name' => 'Again', 'email' => $email, 'phone' => $this->user->phone, 'accept_terms' => true])->assertCreated();
});

it('lists every reason an account cannot be deleted yet', function () {
    $owner = makeUser(RoleName::VendorOwner);
    Vendor::factory()->withMember($owner)->create();
    $rider = makeUser(RoleName::Rider);
    EarningsAccount::query()->forceCreate(['owner_type' => EarningsOwner::Rider->value, 'owner_id' => $rider->id, 'cash_owed_kobo' => 2000]);
    $customer = makeUser();
    $vendor = Checkout::vendor();
    $quote = Checkout::quote(Checkout::token($customer), Checkout::cart($vendor, [[Checkout::product($vendor)]], Checkout::address($customer)))->json('data.id');
    app('auth')->forgetGuards();
    Checkout::place(Checkout::token($customer), $quote)->assertCreated();

    expect(expectApiError(me($owner, ClientApp::Vendor)->postJson('/api/v1/auth/account/delete-code'), 409, 'account_delete_blocked')['meta']['reasons'])->toBe(['store_owner'])
        ->and(expectApiError(me($rider, ClientApp::Rider)->postJson('/api/v1/auth/account/delete-code'), 409, 'account_delete_blocked')['meta']['reasons'])->toBe(['rider_earnings'])
        ->and(expectApiError(me($customer)->deleteJson('/api/v1/auth/account', ['code' => '123456']), 409, 'account_delete_blocked')['meta']['reasons'])->toBe(['open_orders']);
});

it('opens support tickets with an order and photos, and lists only the user\'s own', function () {
    FakeStorage::fake();
    $vendor = Checkout::vendor();
    $quote = Checkout::quote(Checkout::token($this->user), Checkout::cart($vendor, [[Checkout::product($vendor)]], Checkout::address($this->user)))->json('data.id');
    app('auth')->forgetGuards();
    $code = Checkout::place(Checkout::token($this->user), $quote)->json('data.code');
    $photo = ownPath($this->user, 'support');

    $ticket = me($this->user)->postJson('/api/v1/me/support-tickets', ['category' => 'order', 'order_code' => strtolower($code), 'message' => 'The rice was cold when it arrived.', 'photo_paths' => [$photo]])
        ->assertCreated()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.order_code', $code)
        ->assertJsonPath('data.photos.0', FakeStorage::readUrl($photo))
        ->json('data');

    $stranger = makeUser();
    expectApiError(me($this->user)->postJson('/api/v1/me/support-tickets', ['category' => 'order', 'order_code' => 'NOPE1', 'message' => 'Something went wrong here.']), 400, 'validation_failed');
    expectApiError(me($this->user)->postJson('/api/v1/me/support-tickets', ['category' => 'order', 'message' => 'Something went wrong here.', 'photo_paths' => [ownPath($stranger, 'support')]]), 400, 'validation_failed');
    me($this->user)->postJson('/api/v1/me/support-tickets', ['category' => 'account', 'message' => 'Please help me with my account.'])->assertCreated()->assertJsonPath('data.order_code', null);

    me($this->user)->getJson('/api/v1/me/support-tickets')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.id', $ticket['id']);
    me($this->user)->getJson("/api/v1/me/support-tickets/{$ticket['id']}")->assertOk()->assertJsonPath('data.message', 'The rice was cold when it arrived.');
    me($stranger)->getJson("/api/v1/me/support-tickets/{$ticket['id']}")->assertNotFound();
});

it('lets support work a ticket and tells the customer', function () {
    $ticket = new SupportTicket;
    $ticket->forceFill(['user_id' => $this->user->id, 'category' => 'payment', 'message' => 'Charged twice for one order.'])->save();
    $admin = makeUser(RoleName::Admin);

    me($admin, ClientApp::Admin)->getJson('/api/v1/admin/support-tickets?status=open')->assertOk()->assertJsonPath('data.0.user.email', $this->user->email);
    me($admin, ClientApp::Admin)->getJson('/api/v1/admin/support-tickets?status=resolved')->assertOk()->assertJsonCount(0, 'data');
    expectApiError(me($admin, ClientApp::Admin)->patchJson("/api/v1/admin/support-tickets/{$ticket->uuid}", ['status' => 'resolved']), 400, 'validation_failed');

    me($admin, ClientApp::Admin)->patchJson("/api/v1/admin/support-tickets/{$ticket->uuid}", ['status' => 'in_progress'])->assertOk()->assertJsonPath('data.resolved_at', null);
    me($admin, ClientApp::Admin)->patchJson("/api/v1/admin/support-tickets/{$ticket->uuid}", ['status' => 'resolved', 'resolution' => 'Refunded the duplicate charge.'])->assertOk()
        ->assertJsonPath('data.status', 'resolved')
        ->assertJsonPath('data.resolution', 'Refunded the duplicate charge.');
    me($admin, ClientApp::Admin)->patchJson("/api/v1/admin/support-tickets/{$ticket->uuid}", ['status' => 'resolved', 'resolution' => 'Same again.'])->assertOk();

    $bodies = $this->user->notifications()->get()->pluck('data.body')->all();
    expect($bodies)->toHaveCount(2)->toContain('Your support request is now resolved. Open it to see the details.')
        ->and(AdminAuditLog::query()->where('action', 'support_ticket.updated')->count())->toBe(3);
    me($this->user)->getJson('/api/v1/admin/support-tickets')->assertForbidden();
});

it('rebuilds a cart from a past order with what is still on sale', function () {
    $vendor = Checkout::vendor();
    $kept = Checkout::product($vendor, ['name' => 'Jollof rice']);
    $gone = Checkout::product($vendor, ['name' => 'Fried plantain']);
    $soldOut = Checkout::product($vendor, ['name' => 'Moi moi', 'stock_qty' => 3]);
    $group = $vendor->optionGroups()->create(['name' => 'Protein', 'max_select' => 2]);
    $chicken = $group->options()->create(['name' => 'Chicken', 'price_kobo' => 50000]);
    $beef = $group->options()->create(['name' => 'Beef', 'price_kobo' => 50000]);
    $kept->optionGroups()->attach($group->id);
    $token = Checkout::token($this->user);
    $quote = Checkout::quote($token, Checkout::cart($vendor, [[$kept, 2, [$chicken->uuid, $beef->uuid]], [$gone], [$soldOut, 3]], Checkout::address($this->user)))->assertCreated()->json('data.id');
    app('auth')->forgetGuards();
    $code = Checkout::place($token, $quote)->json('data.code');

    $gone->update(['is_active' => false]);
    $beef->update(['is_active' => false]);

    me($this->user)->postJson("/api/v1/me/orders/{$code}/reorder")->assertOk()
        ->assertJsonPath('data.vendor.id', $vendor->uuid)
        ->assertJsonPath('data.items', [['product_id' => $kept->uuid, 'quantity' => 2, 'option_ids' => [$chicken->uuid]]])
        ->assertJsonPath('data.unavailable', [
            ['name' => 'Jollof rice', 'reason' => 'options_changed'],
            ['name' => 'Fried plantain', 'reason' => 'unavailable'],
            ['name' => 'Moi moi', 'reason' => 'unavailable'],
        ]);

    me(makeUser())->postJson("/api/v1/me/orders/{$code}/reorder")->assertNotFound();
    $order = Order::query()->where('code', $code)->sole();
    $order->forceFill(['type' => Order::TYPE_PARCEL])->save();
    expectApiError(me($this->user)->postJson("/api/v1/me/orders/{$code}/reorder"), 422, 'order_not_reorderable');
});
