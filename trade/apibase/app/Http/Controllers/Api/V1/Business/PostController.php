<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Events\MessageSent;
use App\Http\Controllers\Api\V1\Business\Concerns\SerializesBusiness;
use App\Http\Controllers\Controller;
use App\Models\Business\Page;
use App\Models\Conversation;
use App\Models\Post;
use App\Models\User;
use App\Services\AppIdService;
use App\Support\BusinessImage;
use App\Support\Realtime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The feed.
 *
 * What your connections say, what the pages you follow say, and what you
 * say yourself — as you, or as your page. Like, comment, repost to your own
 * feed, or forward to a connection in chat.
 */
class PostController extends Controller
{
    use SerializesBusiness;

    public function feed(Request $request): JsonResponse
    {
        $me = $request->user();

        $query = Post::query()->latest();

        if ($slug = $request->query('page')) {
            $page = Page::live()->where('slug', $slug)->firstOrFail();
            $query->where('page_id', $page->id);
        } elseif ($uuid = $request->query('user')) {
            $user = User::where('uuid', $uuid)->firstOrFail();
            $query->where('user_id', $user->id);
        } elseif ($me->isStaff() && ! $me->businessPage()->exists()) {
            // The GrapOut team with no page picked: every company's posts.
            $query->whereNotNull('page_id');
        } else {
            $connected = $this->connectedIds($me);
            $followed = $me->followedPages()->pluck('business_pages.id')->all();
            $myPage = $me->businessPage()->value('business_pages.id');
            $query->where(fn ($q) => $q
                ->where('user_id', $me->id)
                ->orWhereIn('user_id', $connected)
                ->orWhereIn('page_id', $followed)
                ->when($myPage, fn ($qq) => $qq->orWhere('page_id', $myPage)));
        }

        $rows = $query->paginate(20);
        $rows->getCollection()->transform(fn ($p) => $this->postRow($p, $me));

        return response()->json($rows);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $post = Post::where('uuid', $uuid)->firstOrFail();

        return response()->json(['data' => $this->postRow($post, $request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $me = $request->user();
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'as_page' => ['nullable', 'boolean'],
            'repost_of' => ['nullable', 'string'],
            'images' => ['nullable', 'array', 'max:' . Post::MAX_IMAGES],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $repostOf = null;
        if (! empty($data['repost_of'])) {
            $repostOf = Post::where('uuid', $data['repost_of'])->firstOrFail();
            // Point at the original, never at a repost of it.
            if ($repostOf->repost_of_id) {
                $repostOf = Post::find($repostOf->repost_of_id) ?? $repostOf;
            }
        }

        $hasImages = count($request->file('images', [])) > 0;
        // A request over post_max_size reaches PHP with nothing in it at all — not even the text that was typed.
        $droppedByPhp = ! $hasImages && $request->isMethod('post') && str_starts_with((string) $request->header('Content-Type'), 'multipart/form-data') && $request->all() === [];
        abort_if($droppedByPhp, 413, 'That picture is too large to upload. Try a smaller one.');
        abort_if(empty(trim((string) ($data['body'] ?? ''))) && ! $hasImages && ! $repostOf, 422, 'Say something, or add a picture.');

        $pageId = null;
        if ($request->boolean('as_page')) {
            $pageId = $me->businessPage()->live()->value('business_pages.id');
            abort_unless($pageId, 422, 'You do not have a business page to post as.');
        }

        $images = [];
        foreach ($request->file('images', []) as $file) {
            $stored = BusinessImage::store($file, 'posts/' . $me->id);
            $images[] = ['path' => $stored['path'], 'display' => $stored['thumb_path'] ?: $stored['path']];
        }

        $post = Post::create([
            'user_id' => $me->id,
            'page_id' => $pageId,
            'body' => $data['body'] ?? null,
            'images' => $images ?: null,
            'repost_of_id' => $repostOf?->id,
        ]);

        if ($repostOf) {
            Post::whereKey($repostOf->id)->increment('reposts_count');
            if ($repostOf->user_id !== $me->id) {
                $repostOf->author?->notify(new \App\Notifications\SocialNotification(
                    'post_repost', "{$me->name} shared your post.", ['post_uuid' => $post->uuid], '/feed?post=' . $post->uuid,
                ));
            }
        }

        return response()->json(['message' => 'Posted.', 'data' => $this->postRow($post, $me)], 201);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $post = Post::where('uuid', $uuid)->firstOrFail();
        // The author, the people who run the page it was posted as, or the GrapOut team.
        abort_unless($post->user_id === $me->id || ($post->page_id && Page::find($post->page_id)?->canManage($me)) || $me->isStaff(), 403);
        $post->delete();

        return response()->json(['message' => 'Post removed.']);
    }

    public function like(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $post = Post::where('uuid', $uuid)->firstOrFail();

        $liked = $post->likers()->where('users.id', $me->id)->exists();
        if ($liked) {
            $post->likers()->detach($me->id);
        } else {
            $post->likers()->attach($me->id);
            if ($post->user_id !== $me->id) {
                $post->author?->notify(new \App\Notifications\SocialNotification(
                    'post_like', "{$me->name} liked your post.", ['post_uuid' => $post->uuid], '/feed?post=' . $post->uuid, 'post-like-' . $post->uuid,
                ));
            }
        }
        $post->update(['likes_count' => $post->likers()->count()]);

        return response()->json(['data' => ['liked' => ! $liked, 'likes_count' => $post->likes_count]]);
    }

    public function comments(Request $request, string $uuid): JsonResponse
    {
        $post = Post::where('uuid', $uuid)->firstOrFail();

        return response()->json(['data' => $post->comments()->with('author.profile')->get()->map(fn ($c) => $this->commentRow($c, $request->user()))]);
    }

    public function comment(Request $request, string $uuid): JsonResponse
    {
        $me = $request->user();
        $post = Post::where('uuid', $uuid)->firstOrFail();
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        $comment = $post->comments()->create(['user_id' => $me->id, 'body' => trim($data['body'])]);
        $post->update(['comments_count' => $post->comments()->count()]);

        if ($post->user_id !== $me->id) {
            $post->author?->notify(new \App\Notifications\SocialNotification(
                'post_comment', "{$me->name} commented on your post.", ['post_uuid' => $post->uuid], '/feed?post=' . $post->uuid,
            ));
        }

        return response()->json(['message' => 'Comment added.', 'data' => $this->commentRow($comment, $me)], 201);
    }

    /**
     * Send a post to a connection, in chat.
     *
     * A connection, not anybody: forwarding is a message, and messages go
     * to the people you are connected with. The post travels as its text
     * and a link back to it.
     */
    public function forward(Request $request, string $uuid, AppIdService $appIds): JsonResponse
    {
        $me = $request->user();
        $post = Post::where('uuid', $uuid)->with(['author', 'page'])->firstOrFail();
        $data = $request->validate(['identifier' => ['required', 'string', 'max:255'], 'note' => ['nullable', 'string', 'max:500']]);

        $target = $appIds->findVisibleUser($data['identifier'], $me);
        abort_unless($target && $target->id !== $me->id, 404, 'No connection found by that name.');
        abort_unless($appIds->areConnected($me, $target), 403, 'You can only forward to your connections.');

        $who = $post->page ? $post->page->name : $post->author->name;
        $body = "↪️ Forwarded a post by {$who}:\n"
            . ($post->body ? '"' . mb_strimwidth($post->body, 0, 400, '…') . '"' . "\n" : '')
            . rtrim((string) config('mypa.frontend_url'), '/') . '/feed?post=' . $post->uuid
            . (! empty($data['note']) ? "\n\n" . $data['note'] : '');

        $conversation = Conversation::directBetween($me, $target);
        $message = $conversation->messages()->create(['user_id' => $me->id, 'type' => 'text', 'body' => $body]);
        $conversation->update(['last_message_at' => now()]);
        Realtime::send(new MessageSent($message->load(['user', 'conversation'])));

        return response()->json(['message' => 'Forwarded to ' . $target->name . '.', 'data' => ['conversation_uuid' => $conversation->uuid]]);
    }
}
