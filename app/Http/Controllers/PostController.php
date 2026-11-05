<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\PostMedia;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PostController extends Controller
{
    private function currentUserId(Request $request): ?int
    {
        $user = $request->user('sanctum')
            ?? Auth::guard('sanctum')->user()
            ?? $request->user();

        return $user ? (int) $user->id : null;
    }

    private function absoluteMediaUrl($path): ?string
    {
        if (!$path) {
            return null;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

    private function formatPost(
        Post $post,
        ?int $currentUserId = null
    ): array {
        $post->loadMissing(['user', 'media']);

        $media = $post->media->map(function ($item) {
            $data = $item->toArray();

            foreach (['media_url', 'media_path', 'url', 'path'] as $field) {
                if (!empty($data[$field])) {
                    $data[$field] = $this->absoluteMediaUrl($data[$field]);
                }
            }

            return $data;
        })->values();

        $postData = $post->toArray();

        $postData['user_name'] = $post->user
            ? trim(
                ($post->user->first_name ?? '') . ' ' .
                ($post->user->last_name ?? '')
            )
            : null;

        $postData['user_profile_picture'] = $post->user
            ? $this->absoluteMediaUrl(
                $post->user->profile_picture ?? null
            )
            : null;

        $postData['media'] = $media;

        $postData['like_count'] = DB::table('post_likes')
            ->where('post_id', $post->id)
            ->count();

        $postData['save_count'] = DB::table('post_saves')
            ->where('post_id', $post->id)
            ->count();

        $postData['share_count'] = (int) ($post->share_count ?? 0);

        $postData['is_liked_by_me'] = $currentUserId
            ? DB::table('post_likes')
                ->where('post_id', $post->id)
                ->where('user_id', $currentUserId)
                ->exists()
            : false;

        $postData['is_saved_by_me'] = $currentUserId
            ? DB::table('post_saves')
                ->where('post_id', $post->id)
                ->where('user_id', $currentUserId)
                ->exists()
            : false;

        return $postData;
    }

    /**
     * GET /api/posts
     */
    public function index(Request $request)
    {
        $currentUserId = $this->currentUserId($request);

        $perPage = max(
            1,
            min((int) $request->input('per_page', 10), 100)
        );

        $posts = Post::with(['user', 'media'])
            ->latest()
            ->paginate($perPage);

        $posts->getCollection()->transform(
            fn ($post) => $this->formatPost($post, $currentUserId)
        );

        return response()->json([
            'status' => true,
            'message' => 'Posts fetched successfully.',
            'data' => $posts,
        ]);
    }

    /**
     * GET /api/posts/search?q=hiking
     */
    public function search(Request $request)
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $currentUserId = $this->currentUserId($request);
        $searchTerm = trim($validated['q']);
        $perPage = (int) ($validated['per_page'] ?? 10);

        $posts = Post::with(['user', 'media'])
            ->where(function ($query) use ($searchTerm) {
                $query->where('title', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('description', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('category', 'LIKE', "%{$searchTerm}%");
            })
            ->latest()
            ->paginate($perPage);

        $posts->getCollection()->transform(
            fn ($post) => $this->formatPost($post, $currentUserId)
        );

        return response()->json([
            'status' => true,
            'message' => 'Post search completed successfully.',
            'search_query' => $searchTerm,
            'data' => $posts,
        ]);
    }

    /**
     * GET /api/posts/filter
     */
    public function filter(Request $request)
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'media_type' => ['nullable', Rule::in(['image', 'video'])],
            'liked_by_me' => [
                'nullable',
                Rule::in(['0', '1', 0, 1, true, false]),
            ],
            'sort_by' => [
                'nullable',
                Rule::in(['newest', 'oldest', 'most_liked']),
            ],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $currentUserId = $this->currentUserId($request);
        $perPage = (int) ($validated['per_page'] ?? 10);

        $query = Post::with(['user', 'media']);

        if (!empty($validated['q'])) {
            $searchTerm = trim($validated['q']);

            $query->where(function ($subQuery) use ($searchTerm) {
                $subQuery->where('title', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('description', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('category', 'LIKE', "%{$searchTerm}%");
            });
        }

        if (!empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }

        if (!empty($validated['media_type'])) {
            $mediaType = $validated['media_type'];

            $query->whereHas('media', function ($mediaQuery) use ($mediaType) {
                $mediaQuery->where(function ($subQuery) use ($mediaType) {
                    $subQuery->where('type', $mediaType)
                        ->orWhere('media_type', $mediaType);
                });
            });
        }

        if (
            isset($validated['liked_by_me']) &&
            filter_var($validated['liked_by_me'], FILTER_VALIDATE_BOOLEAN)
        ) {
            if (!$currentUserId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Authentication is required to filter your liked posts.',
                ], 401);
            }

            $query->whereExists(function ($subQuery) use ($currentUserId) {
                $subQuery->select(DB::raw(1))
                    ->from('post_likes')
                    ->whereColumn('post_likes.post_id', 'posts.id')
                    ->where('post_likes.user_id', $currentUserId);
            });
        }

        $sortBy = $validated['sort_by'] ?? 'newest';

        if ($sortBy === 'oldest') {
            $query->orderBy('created_at', 'asc');
        } elseif ($sortBy === 'most_liked') {
            $query->orderByDesc(
                DB::table('post_likes')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('post_likes.post_id', 'posts.id')
            );
        } else {
            $query->latest();
        }

        $posts = $query->paginate($perPage);

        $posts->getCollection()->transform(
            fn ($post) => $this->formatPost($post, $currentUserId)
        );

        return response()->json([
            'status' => true,
            'message' => 'Posts filtered successfully.',
            'filters' => [
                'q' => $validated['q'] ?? null,
                'category' => $validated['category'] ?? null,
                'media_type' => $validated['media_type'] ?? null,
                'liked_by_me' => $validated['liked_by_me'] ?? null,
                'sort_by' => $sortBy,
            ],
            'data' => $posts,
        ]);
    }

    /**
     * GET /api/users/{userId}/posts
     */
    public function userPosts(Request $request, $userId)
    {
        $currentUserId = $this->currentUserId($request);

        $perPage = max(
            1,
            min((int) $request->input('per_page', 10), 100)
        );

        $posts = Post::with(['user', 'media'])
            ->where('user_id', $userId)
            ->latest()
            ->paginate($perPage);

        $posts->getCollection()->transform(
            fn ($post) => $this->formatPost($post, $currentUserId)
        );

        return response()->json([
            'status' => true,
            'message' => 'User posts fetched successfully.',
            'data' => $posts,
        ]);
    }

    /**
     * POST /api/posts
     */
    public function store(Request $request)
    {
        $currentUserId = $this->currentUserId($request);

        if (!$currentUserId) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:100'],
            'media' => ['nullable', 'array'],
            'media.*' => [
                'file',
                'mimes:jpg,jpeg,png,webp,mp4,mov',
                'max:51200',
            ],
        ]);

        $post = Post::create([
            'user_id' => $currentUserId,
            'title' => $validated['title'] ?? null,
            'description' => $validated['description'] ?? null,
            'category' => $validated['category'] ?? null,
        ]);

        if ($request->hasFile('media')) {
            foreach ($request->file('media') as $file) {
                $path = $file->store("posts/{$post->id}", 'public');

                $mediaType = str_starts_with(
                    $file->getMimeType(),
                    'video/'
                ) ? 'video' : 'image';

                PostMedia::create([
                    'post_id' => $post->id,
                    'media_path' => $path,
                    'type' => $mediaType,
                ]);
            }
        }

        // Notify followers when a post is created.
        try {
            $creator = User::find($currentUserId);

            if ($creator) {
                app(NotificationService::class)->notifyFollowers(
                    creator: $creator,
                    type: 'post_created',
                    title: 'New Post',
                    message: trim(
                        ($creator->first_name ?? '') . ' ' .
                        ($creator->last_name ?? '')
                    ) . ' shared a new post.',
                    relatedId: $post->id,
                    relatedType: 'post',
                    url: '/posts/' . $post->id
                );
            }
        } catch (\Throwable $notificationError) {
            report($notificationError);
        }

        $post->load(['user', 'media']);

        return response()->json([
            'status' => true,
            'message' => 'Post created successfully.',
            'data' => $this->formatPost($post, $currentUserId),
        ], 201);
    }

    /**
     * GET /api/posts/{id}
     */
    public function show(Request $request, $id)
    {
        $post = Post::with(['user', 'media'])->find($id);

        if (!$post) {
            return response()->json([
                'status' => false,
                'message' => 'Post not found.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Post fetched successfully.',
            'data' => $this->formatPost(
                $post,
                $this->currentUserId($request)
            ),
        ]);
    }

    /**
     * PUT/PATCH /api/posts/{id}
     */
    public function update(Request $request, $id)
    {
        $currentUserId = $this->currentUserId($request);

        if (!$currentUserId) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $post = Post::where('user_id', $currentUserId)->find($id);

        if (!$post) {
            return response()->json([
                'status' => false,
                'message' => 'Post not found or you are not authorized to update it.',
            ], 404);
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'media' => ['nullable', 'array'],
            'media.*' => [
                'file',
                'mimes:jpg,jpeg,png,webp,mp4,mov',
                'max:51200',
            ],
        ]);

        foreach (['title', 'description', 'category'] as $field) {
            if (array_key_exists($field, $validated)) {
                $post->{$field} = $validated[$field];
            }
        }

        $post->save();

        if ($request->hasFile('media')) {
            foreach ($request->file('media') as $file) {
                $path = $file->store("posts/{$post->id}", 'public');

                $mediaType = str_starts_with(
                    $file->getMimeType(),
                    'video/'
                ) ? 'video' : 'image';

                PostMedia::create([
                    'post_id' => $post->id,
                    'media_path' => $path,
                    'type' => $mediaType,
                ]);
            }
        }

        $post->load(['user', 'media']);

        return response()->json([
            'status' => true,
            'message' => 'Post updated successfully.',
            'data' => $this->formatPost($post, $currentUserId),
        ]);
    }

    /**
     * DELETE /api/posts/{id}
     */
    public function destroy(Request $request, $id)
    {
        $currentUserId = $this->currentUserId($request);

        if (!$currentUserId) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $post = Post::with('media')
            ->where('user_id', $currentUserId)
            ->find($id);

        if (!$post) {
            return response()->json([
                'status' => false,
                'message' => 'Post not found or you are not authorized to delete it.',
            ], 404);
        }

        foreach ($post->media as $media) {
            $path = $media->media_path ?? $media->path ?? null;

            if ($path && !filter_var($path, FILTER_VALIDATE_URL)) {
                Storage::disk('public')->delete($path);
            }
        }

        $post->delete();

        return response()->json([
            'status' => true,
            'message' => 'Post deleted successfully.',
        ]);
    }

    /**
     * POST /api/posts/{id}/like
     */
    public function like(Request $request, $id)
    {
        $currentUserId = $this->currentUserId($request);

        if (!$currentUserId) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $post = Post::find($id);

        if (!$post) {
            return response()->json([
                'status' => false,
                'message' => 'Post not found.',
            ], 404);
        }

        $existingLike = DB::table('post_likes')
            ->where('post_id', $post->id)
            ->where('user_id', $currentUserId)
            ->exists();

        if (!$existingLike) {
            DB::table('post_likes')->insert([
                'post_id' => $post->id,
                'user_id' => $currentUserId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Notify post owner about a new like.
            try {
                $actor = User::find($currentUserId);
                $recipient = User::find($post->user_id);

                if ($actor && $recipient) {
                    $actorName = trim(
                        ($actor->first_name ?? '') . ' ' .
                        ($actor->last_name ?? '')
                    );

                    app(NotificationService::class)->send(
                        recipient: $recipient,
                        data: [
                            'type' => 'post_liked',
                            'title' => 'Post Liked',
                            'message' => ($actorName ?: 'Someone')
                                . ' liked your post.',
                            'actor_id' => $actor->id,
                            'actor_name' => $actorName,
                            'actor_avatar' => $actor->profile_picture,
                            'related_id' => $post->id,
                            'related_type' => 'post',
                            'url' => '/posts/' . $post->id,
                        ],
                        actor: $actor
                    );
                }
            } catch (\Throwable $notificationError) {
                report($notificationError);
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Post liked successfully.',
            'post_id' => $post->id,
            'like_count' => DB::table('post_likes')
                ->where('post_id', $post->id)
                ->count(),
            'is_liked_by_me' => true,
        ]);
    }

    /**
     * DELETE /api/posts/{id}/like
     */
    public function unlike(Request $request, $id)
    {
        $currentUserId = $this->currentUserId($request);

        if (!$currentUserId) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $post = Post::find($id);

        if (!$post) {
            return response()->json([
                'status' => false,
                'message' => 'Post not found.',
            ], 404);
        }

        DB::table('post_likes')
            ->where('post_id', $post->id)
            ->where('user_id', $currentUserId)
            ->delete();

        return response()->json([
            'status' => true,
            'message' => 'Post unliked successfully.',
            'post_id' => $post->id,
            'like_count' => DB::table('post_likes')
                ->where('post_id', $post->id)
                ->count(),
            'is_liked_by_me' => false,
        ]);
    }

    /**
     * GET /api/posts/{id}/likes
     */
    public function likesList($id)
    {
        $post = Post::find($id);

        if (!$post) {
            return response()->json([
                'status' => false,
                'message' => 'Post not found.',
            ], 404);
        }

        $likes = DB::table('post_likes')
            ->join('users', 'users.id', '=', 'post_likes.user_id')
            ->where('post_likes.post_id', $post->id)
            ->select(
                'users.id',
                'users.first_name',
                'users.last_name',
                'users.profile_picture',
                'post_likes.created_at as liked_at'
            )
            ->latest('post_likes.created_at')
            ->paginate(20);

        $likes->getCollection()->transform(function ($user) {
            $user->name = trim(
                ($user->first_name ?? '') . ' ' .
                ($user->last_name ?? '')
            );

            $user->profile_picture = $this->absoluteMediaUrl(
                $user->profile_picture
            );

            return $user;
        });

        return response()->json([
            'status' => true,
            'message' => 'Post likes fetched successfully.',
            'data' => $likes,
        ]);
    }

    /**
     * POST /api/posts/{id}/share
     */
    public function share($id)
    {
        $post = Post::find($id);

        if (!$post) {
            return response()->json([
                'status' => false,
                'message' => 'Post not found.',
            ], 404);
        }

        $post->increment('share_count');

        return response()->json([
            'status' => true,
            'message' => 'Post shared successfully.',
            'post_id' => $post->id,
            'share_count' => (int) $post->fresh()->share_count,
        ]);
    }

    /**
     * POST /api/posts/{id}/save
     */
    public function save(Request $request, $id)
    {
        $currentUserId = $this->currentUserId($request);

        if (!$currentUserId) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $post = Post::find($id);

        if (!$post) {
            return response()->json([
                'status' => false,
                'message' => 'Post not found.',
            ], 404);
        }

        DB::table('post_saves')->updateOrInsert(
            [
                'post_id' => $post->id,
                'user_id' => $currentUserId,
            ],
            [
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return response()->json([
            'status' => true,
            'message' => 'Post saved successfully.',
            'post_id' => $post->id,
            'is_saved_by_me' => true,
        ]);
    }

    /**
     * DELETE /api/posts/{id}/save
     */
    public function unsave(Request $request, $id)
    {
        $currentUserId = $this->currentUserId($request);

        if (!$currentUserId) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        DB::table('post_saves')
            ->where('post_id', $id)
            ->where('user_id', $currentUserId)
            ->delete();

        return response()->json([
            'status' => true,
            'message' => 'Post removed from saved posts.',
            'post_id' => (int) $id,
            'is_saved_by_me' => false,
        ]);
    }

    /**
     * GET /api/posts/saved
     */
    public function savedPosts(Request $request)
    {
        $currentUserId = $this->currentUserId($request);

        if (!$currentUserId) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $perPage = max(
            1,
            min((int) $request->input('per_page', 10), 100)
        );

        $posts = Post::with(['user', 'media'])
            ->whereIn('id', function ($query) use ($currentUserId) {
                $query->select('post_id')
                    ->from('post_saves')
                    ->where('user_id', $currentUserId);
            })
            ->latest()
            ->paginate($perPage);

        $posts->getCollection()->transform(
            fn ($post) => $this->formatPost($post, $currentUserId)
        );

        return response()->json([
            'status' => true,
            'message' => 'Saved posts fetched successfully.',
            'data' => $posts,
        ]);
    }
}
