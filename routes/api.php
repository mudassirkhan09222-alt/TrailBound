<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\TripMembershipController;
use App\Http\Controllers\TrailController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\TrailReportController;
use App\Http\Controllers\TripReviewController;
use App\Http\Controllers\TripReportController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\CompanionController;
use App\Http\Controllers\ProfileReviewController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\FollowController;
use App\Http\Controllers\UserActionController;
use App\Http\Controllers\PostReportController;
use App\Http\Controllers\PostCommentController;
use App\Http\Controllers\CommentReplyController;
use App\Http\Controllers\CommentLikeController;
use App\Http\Controllers\AiAssistantController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\UserSearchController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\ReplyController;

/*
|--------------------------------------------------------------------------
| API Routes - TrailBound
|--------------------------------------------------------------------------
*/

// ==========================================
// 1. PUBLIC ROUTES
// ==========================================

// Authentication
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// Public partners
Route::get('/partners', [ProfileController::class, 'partners']);

// Public trails
Route::get('/trails', [TrailController::class, 'index']);
Route::get('/trails/{id}', [TrailController::class, 'show'])->whereNumber('id');
Route::get('/trails/{id}/community', [TrailController::class, 'community'])->whereNumber('id');

// Public trail reviews and reports
Route::get('/trails/{id}/reviews', [ReviewController::class, 'index'])->whereNumber('id');
Route::get('/trails/{id}/reports', [TrailReportController::class, 'index'])->whereNumber('id');

// Public user profiles
Route::get('/users/{id}/profile', [ProfileController::class, 'showById'])->whereNumber('id');

// Public posts
Route::get('/posts', [PostController::class, 'index']);
Route::get('/posts/search', [PostController::class, 'search']);
Route::get('/posts/filter', [PostController::class, 'filter']);
Route::get('/posts/{id}', [PostController::class, 'show'])->whereNumber('id');

// Public followers and following
Route::get('/users/{id}/followers', [FollowController::class, 'followers'])->whereNumber('id');
Route::get('/users/{id}/following', [FollowController::class, 'following'])->whereNumber('id');

// Public profile reviews
Route::get('/users/{id}/profile-reviews', [ProfileReviewController::class, 'index'])->whereNumber('id');


// ==========================================
// 2. PROTECTED ROUTES (Sanctum authentication required)
// ==========================================

Route::middleware('auth:sanctum')->group(function () {

    // AUTH & ACCOUNT
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/deactivate', [AuthController::class, 'deactivateAccount']);

    // USER AND PROFILE
    Route::get('/users', [ProfileController::class, 'index']);
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::patch('/profile', [ProfileController::class, 'update']);
    Route::post('/profile/change-password', [ProfileController::class, 'changePassword']);
    Route::post('/user/location', [AuthController::class, 'updateLocation']);
    Route::get('/users/search', [UserSearchController::class, 'index'])->name('user.search');
    
    // HELP & SUPPORT
    Route::get('/help/faqs', [ProfileController::class, 'getFaqs']);
    Route::get('/legal/terms', [ProfileController::class, 'getTerms']);
    Route::post('/help/support', [ProfileController::class, 'submitSupport']);

    // HOME
    Route::get('/home', [HomeController::class, 'index']);
    Route::get('/recommended-trips/see-all', [HomeController::class, 'seeAllRecommendedTrips']); // Updated
    Route::get('/nearby-trips/see-all', [HomeController::class, 'seeAllNearbyTrips']);


    // USER REPORT AND BLOCK
    Route::post('/users/{id}/report', [UserActionController::class, 'report'])->whereNumber('id');
    Route::post('/users/{id}/block', [UserActionController::class, 'toggleBlock'])->whereNumber('id');
    Route::get('/users/{id}/block-status', [UserActionController::class, 'blockStatus'])->whereNumber('id');

    // FOLLOW SYSTEM
    Route::post('/users/{id}/follow', [FollowController::class, 'toggle'])->whereNumber('id');
    Route::delete('/users/{followerId}/remove-follower', [FollowController::class, 'removeFollower'])->whereNumber('followerId');

    // POSTS
    Route::post('/posts', [PostController::class, 'store']);
    Route::get('/posts/saved', [PostController::class, 'savedPosts']);
    Route::get('/users/{userId}/posts', [PostController::class, 'userPosts'])->whereNumber('userId');
    
    Route::post('/posts/{id}/like', [PostController::class, 'like'])->whereNumber('id');
    Route::delete('/posts/{id}/like', [PostController::class, 'unlike'])->whereNumber('id');
    Route::get('/posts/{id}/likes', [PostController::class, 'likesList'])->whereNumber('id');
    
    Route::post('/posts/{id}/share', [PostController::class, 'share'])->whereNumber('id');
    Route::post('/posts/{id}/save', [PostController::class, 'save'])->whereNumber('id');
    Route::delete('/posts/{id}/save', [PostController::class, 'unsave'])->whereNumber('id');
    
    Route::post('/posts/{postId}/report', [PostReportController::class, 'store'])->whereNumber('postId');
    Route::get('/my-reports', [PostReportController::class, 'index']);
    
    Route::get('/posts/{postId}/comments', [PostCommentController::class, 'index'])->whereNumber('postId');
    Route::post('/posts/{postId}/comments', [PostCommentController::class, 'store'])->whereNumber('postId');
    
    Route::put('/posts/{id}', [PostController::class, 'update'])->whereNumber('id');
    Route::patch('/posts/{id}', [PostController::class, 'update'])->whereNumber('id');
    Route::delete('/posts/{id}', [PostController::class, 'destroy'])->whereNumber('id');

    // TRIPS
    Route::get('/trips', [TripController::class, 'index']);
    Route::post('/trips', [TripController::class, 'store']);
    Route::get('/trips/{id}', [TripController::class, 'show'])->whereNumber('id');
    Route::put('/trips/{id}', [TripController::class, 'update'])->whereNumber('id');
    Route::delete('/trips/{id}', [TripController::class, 'destroy'])->whereNumber('id');
    Route::post('/trips/{id}/join', [TripMembershipController::class, 'join'])->whereNumber('id');
    Route::delete('/trips/{id}/leave', [TripMembershipController::class, 'leave'])->whereNumber('id');
    Route::patch('/trips/{tripId}/members/{userId}', [TripMembershipController::class, 'updateMemberStatus'])->whereNumber('tripId')->whereNumber('userId');
    Route::get('/users/{userId}/trips', [TripController::class, 'userTrips'])->whereNumber('userId');
    Route::post('/trips/{trip}/toggle-like', [TripController::class, 'toggleLike']);
    Route::get('/trips/{trip}/likes', [TripController::class, 'getLikes']);

    // TRAILS
    Route::post('/trails', [TrailController::class, 'store']);
    Route::put('/trails/{id}', [TrailController::class, 'update'])->whereNumber('id');
    Route::delete('/trails/{id}', [TrailController::class, 'destroy'])->whereNumber('id');
    Route::get('/trails/user/{userId}', [TrailController::class, 'userTrails'])->whereNumber('userId');
    Route::post('/trails/{trail}/toggle-like', [TrailController::class, 'toggleLike']);
    Route::get('/trails/{trail}/likes', [TrailController::class, 'getLikes']);
    
    // TRAIL REVIEWS & REPORTS
    Route::post('/trails/{id}/reviews', [ReviewController::class, 'store'])->whereNumber('id');
    Route::put('/reviews/{id}', [ReviewController::class, 'update'])->whereNumber('id');
    Route::delete('/reviews/{id}', [ReviewController::class, 'destroy'])->whereNumber('id');
    
    Route::post('/trails/{id}/reports', [TrailReportController::class, 'store'])->whereNumber('id');
    Route::put('/reports/{id}', [TrailReportController::class, 'update'])->whereNumber('id');
    Route::delete('/reports/{id}', [TrailReportController::class, 'destroy'])->whereNumber('id');
    Route::post('/reports/{id}/confirm', [TrailReportController::class, 'confirm'])->whereNumber('id');

    // TRIP REVIEWS & REPORTS
    Route::get('/trips/{id}/reviews', [TripReviewController::class, 'index'])->whereNumber('id');
    Route::post('/trips/{id}/reviews', [TripReviewController::class, 'store'])->whereNumber('id');
    Route::put('/trip-reviews/{id}', [TripReviewController::class, 'update'])->whereNumber('id');
    Route::delete('/trip-reviews/{id}', [TripReviewController::class, 'destroy'])->whereNumber('id');
    
    Route::get('/trips/{id}/reports', [TripReportController::class, 'index'])->whereNumber('id');
    Route::post('/trips/{id}/reports', [TripReportController::class, 'store'])->whereNumber('id');
    Route::put('/trip-reports/{id}', [TripReportController::class, 'update'])->whereNumber('id');
    Route::delete('/trip-reports/{id}', [TripReportController::class, 'destroy'])->whereNumber('id');
    Route::post('/trip-reports/{id}/confirm', [TripReportController::class, 'confirm'])->whereNumber('id');

    // COMPANIONS
    Route::prefix('companions')->group(function () {
        Route::get('/suggested', [CompanionController::class, 'suggested']);
        Route::post('/request/{userId}', [CompanionController::class, 'sendRequest'])->whereNumber('userId');
        Route::post('/accept/{requestId}', [CompanionController::class, 'acceptRequest'])->whereNumber('requestId');
        Route::delete('/{requestId}', [CompanionController::class, 'destroy'])->whereNumber('requestId');
        Route::get('/', [CompanionController::class, 'index']);
    });

    // PROFILE REVIEWS
    Route::post('/profile-reviews', [ProfileReviewController::class, 'store']);
    Route::put('/profile-reviews/{id}', [ProfileReviewController::class, 'update'])->whereNumber('id');
    Route::delete('/profile-reviews/{id}', [ProfileReviewController::class, 'destroy'])->whereNumber('id');

    // COMMENT DETAILS & REPLIES
    Route::get('/comments/{commentId}', [PostCommentController::class, 'show'])->whereNumber('commentId');
    Route::put('/comments/{commentId}', [PostCommentController::class, 'update'])->whereNumber('commentId');
    Route::delete('/comments/{commentId}', [PostCommentController::class, 'destroy'])->whereNumber('commentId');
    
    Route::get('/comments/{commentId}/replies', [CommentReplyController::class, 'index'])->whereNumber('commentId');
    Route::post('/comments/{commentId}/replies', [CommentReplyController::class, 'store'])->whereNumber('commentId');
    Route::get('/comment-replies/{replyId}', [CommentReplyController::class, 'show'])->whereNumber('replyId');
    Route::put('/comment-replies/{replyId}', [CommentReplyController::class, 'update'])->whereNumber('replyId');
    Route::delete('/comment-replies/{replyId}', [CommentReplyController::class, 'destroy'])->whereNumber('replyId');

    // COMMENT LIKES
    Route::post('/comments/{commentId}/like', [CommentLikeController::class, 'store'])->whereNumber('commentId');
    Route::delete('/comments/{commentId}/like', [CommentLikeController::class, 'destroy'])->whereNumber('commentId');
    Route::get('/comments/{commentId}/likes', [CommentLikeController::class, 'index'])->whereNumber('commentId');

    // AI ASSISTANT
    Route::post('/ai/chat', [AiAssistantController::class, 'chat']);
    Route::get('/ai/conversations', [AiAssistantController::class, 'conversations']);
    Route::get('/ai/conversations/{id}', [AiAssistantController::class, 'show'])->whereNumber('id');
    Route::delete('/ai/conversations/{id}', [AiAssistantController::class, 'destroy'])->whereNumber('id');

    // NOTIFICATIONS
    Route::prefix('notifications')->controller(NotificationController::class)->group(function () {
        Route::get('/', 'index');
        Route::get('/unread-count', 'unreadCount');
        Route::patch('/read-all', 'markAllAsRead');
        Route::patch('/{id}/read', 'markAsRead');
        Route::delete('/{id}', 'destroy');
    });

    // MESSAGING SYSTEM 
    Route::get('/messages', [MessageController::class, 'index']);
    Route::get('/messages/users', [MessageController::class, 'getUsersForChat']);
    Route::get('/messages/{userId}', [MessageController::class, 'showConversation'])->whereNumber('userId');
    Route::post('/messages', [MessageController::class, 'store']);
    Route::put('/messages/{id}', [MessageController::class, 'update'])->whereNumber('id');
    Route::delete('/messages/{id}', [MessageController::class, 'destroy'])->whereNumber('id');

    // REPLY SYSTEM
    Route::post('/messages/{id}/replies', [ReplyController::class, 'store'])->whereNumber('id');
    Route::get('/messages/{id}/replies', [ReplyController::class, 'index'])->whereNumber('id');
    Route::put('/replies/{id}', [ReplyController::class, 'update'])->whereNumber('id');
    Route::delete('/replies/{id}', [ReplyController::class, 'destroy'])->whereNumber('id');

});