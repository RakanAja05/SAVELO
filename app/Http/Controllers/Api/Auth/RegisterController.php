<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Models\RegistrationOtp;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;

class RegisterController extends Controller
{
    /**
     * Step 1: Terima data registrasi, kirim OTP ke email.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Hapus OTP lama untuk email ini (jika ada)
        RegistrationOtp::where('email', $data['email'])->delete();

        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        RegistrationOtp::create([
            'email' => $data['email'],
            'otp' => $otp, // ⚠️ hash di production: bcrypt($otp)
            'payload' => $data,
            'expires_at' => now()->addMinutes(10),
        ]);

        // Kirim OTP via email
        Mail::raw("Kode OTP registrasi kamu: {$otp} (berlaku 10 menit)", function ($msg) use ($data) {
            $msg->to($data['email'])->subject('OTP Registrasi');
        });

        return ApiResponse::success('OTP telah dikirim ke email kamu.', [
            'email' => $data['email'],
        ], 200);
    }

    /**
     * Step 2: Verifikasi OTP, buat akun, kembalikan token.
     */
    public function verify(VerifyOtpRequest $request): JsonResponse
    {
        $data = $request->validated();

        $record = RegistrationOtp::where('email', $data['email'])
            ->where('otp', $data['otp']) // jika di-hash: Hash::check($data['otp'], ...)
            ->first();

        if (! $record) {
            return ApiResponse::error('OTP tidak valid.', null, 422);
        }

        if ($record->isExpired()) {
            $record->delete();

            return ApiResponse::error('OTP sudah kedaluwarsa.', null, 422);
        }

        // Buat user dari payload yang disimpan
        $user = User::create($record->payload);
        $token = $user->createToken('auth_token')->plainTextToken;

        $record->delete();

        return ApiResponse::success('Register berhasil.', [
            'user' => $user,
            'token' => $token,
            'token_type' => 'Bearer',
        ], 201);
    }
}
