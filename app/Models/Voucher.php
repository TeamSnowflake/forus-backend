<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Class Voucher
 * @property mixed $id
 * @property integer $fund_id
 * @property integer|null $product_id
 * @property integer|null $parent_id
 * @property integer $identity_address
 * @property string $amount
 * @property string $type
 * @property float $amount_available
 * @property float $amount_available_cached
 * @property Fund $fund
 * @property Product|null $product
 * @property Voucher|null $parent
 * @property Collection $tokens
 * @property Collection $transactions
 * @property Collection $product_vouchers
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon $expire_at
 * @package App\Models
 */
class Voucher extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'fund_id', 'identity_address', 'amount', 'product_id', 'parent_id', 'expire_at'
    ];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = [
        'expire_at'
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function fund() {
        return $this->belongsTo(Fund::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function parent() {
        return $this->belongsTo(Voucher::class, 'parent_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function product() {
        return $this->belongsTo(
            Product::class, 'product_id', 'id'
        )->withTrashed();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function transactions() {
        return $this->hasMany(VoucherTransaction::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function product_vouchers() {
        return $this->hasMany(Voucher::class, 'parent_id');
    }

    /**
     * @return string
     */
    public function getTypeAttribute() {
        return $this->product_id ? 'product' : 'regular';
    }

    public function getAmountAvailableAttribute() {
        return round($this->amount -
            $this->transactions()->sum('amount') -
            $this->product_vouchers()->sum('amount'), 2);
    }

    public function getAmountAvailableCachedAttribute() {
        return round($this->amount -
            $this->transactions->sum('amount') -
            $this->product_vouchers->sum('amount'), 2);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function tokens() {
        return $this->hasMany(VoucherToken::class);
    }

    /**
     * The voucher is expired
     *
     * @return bool
     */
    public function getExpiredAttribute() {
        return !!$this->expire_at->isPast();
    }

    public function sendToEmail() {
        /** @var VoucherToken $voucherToken */
        $voucherToken = $this->tokens()->where([
            'need_confirmation' => false
        ])->first();

        if ($voucherToken->voucher->type == 'product') {
            $fund_product_name = $voucherToken->voucher->product->name;
        } else {
            $fund_product_name = $voucherToken->voucher->fund->name;
        }

        resolve('forus.services.mail_notification')->sendVoucher(
            auth()->user()->getAuthIdentifier(),
            $fund_product_name,
            $voucherToken->getQrCodeUrl()
        );
    }

    /**
     * @param string $reason
     * @param bool $sendCopyToUser
     */
    public function shareVoucherEmail(string $reason, $sendCopyToUser = false) {
        /** @var VoucherToken $voucherToken */
        $voucherToken = $this->tokens()->where([
            'need_confirmation' => false
        ])->first();

        if ($voucherToken->voucher->type == 'product') {

            $recordRepo = resolve('forus.services.record');
            $primaryEmail = $recordRepo->primaryEmailByAddress(auth()->id());

            $product_name = $voucherToken->voucher->product->name;

            resolve('forus.services.mail_notification')->shareVoucher(
                $voucherToken->voucher->product->organization->emailServiceId(),
                $primaryEmail,
                $product_name,
                $voucherToken->getQrCodeUrl(),
                $reason
            );

            if ($sendCopyToUser) {
                resolve('forus.services.mail_notification')->shareVoucher(
                    auth()->id(),
                    $primaryEmail,
                    $product_name,
                    $voucherToken->getQrCodeUrl(),
                    $reason
                );
            }
        }
    }

    /**
     * @return void
     */
    public function sendEmailAvailableAmount()
    {
        $amount = $this->parent ? $this->parent->amount_available : $this->amount_available;
        $fund_name = $this->fund->name;

        resolve('forus.services.mail_notification')->transactionAvailableAmount(
            $this->identity_address,
            $fund_name,
            $amount
        );
    }

    /**
     *
     */
    public static function checkVoucherExpireQueue()
    {
        $date = now()->addDays(4*7)->startOfDay();
        $vouchers = self::query()
            ->whereNull('product_id')
            ->with(['fund', 'fund.organization'])
            ->whereDate('expire_at', $date)
            ->get();

        /** @var self $voucher */
        foreach ($vouchers as $voucher) {

            if($voucher->amount_available_cached > 0){

                $recordRepo = resolve('forus.services.record');
                $primaryEmail = $recordRepo->primaryEmailByAddress($voucher->identity_address);

                $fund_name = $voucher->fund->name;
                $sponsor_name = $voucher->fund->organization->name;
                $start_date = $voucher->fund->start_date->format('Y');
                $end_date = $voucher->fund->end_date->format('d/m/Y');
                $phone = $voucher->fund->organization->phone;
                $email = $voucher->fund->organization->email;
                $webshopLink = env('WEB_SHOP_GENERAL_URL');

                resolve('forus.services.mail_notification')->voucherExpire(
                    $primaryEmail,
                    $fund_name,
                    $sponsor_name,
                    $start_date,
                    $end_date,
                    $phone,
                    $email,
                    $webshopLink
                );
            }
        }
    }

}
