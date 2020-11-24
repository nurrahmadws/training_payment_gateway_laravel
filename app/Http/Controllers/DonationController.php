<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Donation;

class DonationController extends Controller
{
    public function __construct()
    {
        \Midtrans\Config::$serverKey = config('services.midtrans.serverKey');
        \Midtrans\Config::$isProduction = config('services.midtrans.isProduction');
        \Midtrans\Config::$isSanitized = config('services.midtrans.isSanitized');
        \Midtrans\Config::$is3ds = config('services.midtrans.is3ds');
    }

    public function index()
    {
        $donations = Donation::orderBy('id', 'desc')->paginate(8);
        return view('welcome', compact('donations'));
    }

    public function create()
    {
        return view('donation');
    }

    public function store(Request $request)
    {
        // return response()->json($request);
        \DB::transaction(function() use ($request){
            $donation = Donation::create([
                'donator' => $request->donator,
                'donator_email' => $request->donator_email,
                'donation_type' => $request->donation_type,
                'amount' => floatval($request->amount),
                'note' => $request->note,
                'donation_code' => 'SANDBOX-'.uniqid()
            ]);

            $payload = [
                'transaction_details' => [
                    'order_id' => $donation->donation_code,
                    'gross_amount' => $donation->amount
                ],
                'customer_details' => [
                    'first_name' => $donation->donator,
                    'email' => $donation->donator_email
                ],
                'item_details' => [
                    [
                        'id' => $donation->donation_type,
                        'price' => $donation->amount,
                        'quantity' => 1,
                        'name' => ucwords(str_replace('_', ' ', $donation->donation_type))
                    ]
                ]
            ];

            $snapToken = \Midtrans\Snap::getSnapToken($payload);
            $donation->snap_token = $snapToken;
            $donation->save();

            $this->response['snap_token'] = $snapToken;
        });

        return response()->json($this->response);
    }

    public function notification()
    {
        $notif = new \Midtrans\Notification();

        \DB::transaction(function() use($notif){
            $transaction = $notif->transaction_status;
            $type = $notif->payment_type;
            $orderId = $notif->order_id;
            $fraud = $notif->fraud_status;
            $donation = Donation::findOrFail($orderId);
            error_log("Order ID $notif->order_id: "."transaction status = $transaction, fraud staus = $fraud");
            if ($transaction == 'capture') {
                if ($type == 'credit_card') {

                    if($fraud == 'challenge') {
                        $donation->setStatusPending();
                    } else {
                        $donation->setStatusSuccess();
                    }

                }
            } elseif ($transaction == 'settlement') {

                $donation->setStatusSuccess();

            } elseif($transaction == 'pending'){

                $donation->setStatusPending();

            } elseif ($transaction == 'deny') {

                $donation->setStatusFailed();

            } elseif ($transaction == 'expire') {

                $donation->setStatusExpired();

            } elseif ($transaction == 'cancel') {

                $donation->setStatusFailed();

            }
        });

        return;
    }
}
