<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\RegistrationOtp;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;

class ForgotPasswordController extends Controller
{
    /**
     * Step 1: Kirim OTP reset password ke email.
     */
    public function requestOtp(ForgotPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::where('email', $data['email'])->first();

        if (! $user) {
            return ApiResponse::error('Email tidak terdaftar.', null, 404);
        }

        RegistrationOtp::where('email', $data['email'])->delete();

        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        RegistrationOtp::create([
            'email' => $data['email'],
            'otp' => $otp, // hash di production: bcrypt($otp)
            'payload' => ['type' => 'password_reset'],
            'expires_at' => now()->addMinutes(10),
        ]);

        Mail::raw("Kode OTP reset password kamu: {$otp} (berlaku 10 menit)", function ($msg) use ($data) {
            $msg->to($data['email'])->subject('OTP Reset Password');
        });

        return ApiResponse::success('OTP reset password telah dikirim ke email kamu.', [
            'email' => $data['email'],
        ]);
    }

    /**
     * Step 2: Verifikasi OTP dan reset password.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();

        $record = RegistrationOtp::where('email', $data['email'])
            ->where('otp', $data['otp'])
            ->first();

        if (! $record) {
            return ApiResponse::error('OTP tidak valid.', null, 422);
        }

        if ($record->isExpired()) {
            $record->delete();

            return ApiResponse::error('OTP sudah kedaluwarsa.', null, 422);
        }

        $user = User::where('email', $data['email'])->first();

        if (! $user) {
            $record->delete();

            return ApiResponse::error('Email tidak terdaftar.', null, 404);
        }

        $user->forceFill([
            'password' => $data['password'],
        ])->save();

        $user->tokens()->delete();
        $record->delete();

        return ApiResponse::success('Password berhasil direset.', null, 200);
    }
}
