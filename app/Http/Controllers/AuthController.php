<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\User;

class AuthController extends Controller
{
    // REGISTER
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string',
            'last_name' => 'required|string',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|unique:users,phone',
            'location' => 'required|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'password' => 'required|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Validation Error', 'errors' => $validator->errors()], 422);
        }

        $user = User::create([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'phone' => $request->phone,
            'location' => $request->location,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'password' => Hash::make($request->password),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status' => true,
            'message' => 'User registered successfully',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => trim($user->first_name . ' ' . $user->last_name),
                'email' => $user->email,
            ],
        ], 201);
    }

    // LOGIN
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Validation Error', 'errors' => $validator->errors()], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid credentials',
            ], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'status' => true,
            'message' => 'User logged in successfully',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => trim($user->first_name . ' ' . $user->last_name),
                'email' => $user->email,
            ],
        ]);
    }

    // LOGOUT
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'status' => true,
            'message' => 'User logged out successfully',
        ]);
    }

    /**
     * POST /api/auth/deactivate
     * Account Deactivate karna (Password verification + Soft Delete)
     */
    public function deactivateAccount(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        // 1. Password Validation
        $validator = Validator::make($request->all(), [
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // 2. Verify Password
        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'status' => false,
                'message' => 'Incorrect password provided.',
            ], 403);
        }

        // 3. Deactivate Account (Soft Delete)
        // Note: Ensure your users table has an 'is_active' column (default true)
        $user->update(['is_active' => false]);

        // 4. Revoke all tokens (Force Logout from all devices)
        $user->tokens()->delete();

        return response()->json([
            'status' => true,
            'message' => 'Account deactivated successfully.',
        ]);
    }

    // FORGOT PASSWORD
    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Validation Error', 'errors' => $validator->errors()], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ], 404);
        }

        $otp = rand(100000, 999999);

        DB::table('password_otps')
            ->where('email', $request->email)
            ->delete();

        DB::table('password_otps')->insert([
            'email' => $request->email,
            'otp' => $otp,
            'verified' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'OTP generated successfully',
        ]);
    }

    // VERIFY OTP
    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'otp' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Validation Error', 'errors' => $validator->errors()], 422);
        }

        $otpData = DB::table('password_otps')
            ->where('email', $request->email)
            ->where('otp', $request->otp)
            ->first();

        if (!$otpData) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid OTP',
            ], 400);
        }

        DB::table('password_otps')
            ->where('id', $otpData->id)
            ->update([
                'verified' => true,
                'updated_at' => now(),
            ]);

        return response()->json([
            'status' => true,
            'message' => 'OTP verified successfully',
        ]);
    }

    // RESET PASSWORD
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Validation Error', 'errors' => $validator->errors()], 422);
        }

        $otpData = DB::table('password_otps')
            ->where('email', $request->email)
            ->where('verified', true)
            ->first();

        if (!$otpData) {
            return response()->json([
                'status' => false,
                'message' => 'Please verify OTP first',
            ], 400);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ], 404);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        DB::table('password_otps')
            ->where('email', $request->email)
            ->delete();

        return response()->json([
            'status' => true,
            'message' => 'Password reset successfully',
        ]);
    }

    // UPDATE LOCATION
    public function updateLocation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'location' => 'required|string',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'Validation Error', 'errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        $user->location = $request->location;
        $user->latitude = $request->latitude;
        $user->longitude = $request->longitude;
        $user->save();

        return response()->json([
            'status' => true,
            'message' => 'Location updated successfully',
            'user' => [
                'id' => $user->id,
                'name' => trim($user->first_name . ' ' . $user->last_name),
                'location' => $user->location,
                'latitude' => $user->latitude,
                'longitude' => $user->longitude,
            ],
        ]);
    }
}