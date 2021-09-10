<?php

namespace RTippin\Messenger\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RTippin\Messenger\Contracts\MessengerProvider;
use RTippin\Messenger\Contracts\Ownerable;
use RTippin\Messenger\Database\Factories\MessageFactory;
use RTippin\Messenger\Facades\Messenger;
use RTippin\Messenger\Support\Helpers;
use RTippin\Messenger\Traits\HasOwner;
use RTippin\Messenger\Traits\ScopesProvider;
use RTippin\Messenger\Traits\Uuids;

/**
 * @property string $id
 * @property string $thread_id
 * @property int $type
 * @property string|null $body
 * @property string $reply_to_id
 * @property bool $edited
 * @property bool $reacted
 * @property bool $embeds
 * @property string|array|null $extra
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \RTippin\Messenger\Models\Thread $thread
 * @property-read \RTippin\Messenger\Models\MessageEdit $edits
 * @property-read \RTippin\Messenger\Models\MessageReaction $reactions
 * @property-read \RTippin\Messenger\Models\Message $replyTo
 *
 * @method static \Illuminate\Database\Query\Builder|Message onlyTrashed()
 * @method static \Illuminate\Database\Query\Builder|Message withTrashed()
 * @method static \Illuminate\Database\Query\Builder|Message withoutTrashed()
 * @mixin Model|\Eloquent
 *
 * @method static Builder|Message text()
 * @method static Builder|Message document()
 * @method static Builder|Message image()
 * @method static Builder|Message audio()
 * @method static Builder|Message system()
 * @method static Builder|Message nonSystem()
 */
class Message extends Model implements Ownerable
{
    use HasFactory,
        HasOwner,
        ScopesProvider,
        SoftDeletes,
        Uuids;

    const MESSAGE = 0;
    const IMAGE_MESSAGE = 1;
    const DOCUMENT_MESSAGE = 2;
    const AUDIO_MESSAGE = 3;
    const PARTICIPANT_JOINED_WITH_INVITE = 88;
    const VIDEO_CALL = 90;
    const GROUP_AVATAR_CHANGED = 91;
    const THREAD_ARCHIVED = 92;
    const GROUP_CREATED = 93;
    const GROUP_RENAMED = 94;
    const DEMOTED_ADMIN = 95;
    const PROMOTED_ADMIN = 96;
    const PARTICIPANT_LEFT_GROUP = 97;
    const PARTICIPANT_REMOVED = 98;
    const PARTICIPANTS_ADDED = 99;
    const BOT_ADDED = 100;
    const BOT_RENAMED = 101;
    const BOT_AVATAR_CHANGED = 102;
    const BOT_REMOVED = 103;
    const NonSystemTypes = [
        self::MESSAGE,
        self::IMAGE_MESSAGE,
        self::DOCUMENT_MESSAGE,
        self::AUDIO_MESSAGE,
    ];
    const TYPE = [
        0 => 'MESSAGE',
        1 => 'IMAGE_MESSAGE',
        2 => 'DOCUMENT_MESSAGE',
        3 => 'AUDIO_MESSAGE',
        88 => 'PARTICIPANT_JOINED_WITH_INVITE',
        90 => 'VIDEO_CALL',
        91 => 'GROUP_AVATAR_CHANGED',
        92 => 'THREAD_ARCHIVED',
        93 => 'GROUP_CREATED',
        94 => 'GROUP_RENAMED',
        95 => 'DEMOTED_ADMIN',
        96 => 'PROMOTED_ADMIN',
        97 => 'PARTICIPANT_LEFT_GROUP',
        98 => 'PARTICIPANT_REMOVED',
        99 => 'PARTICIPANTS_ADDED',
        100 => 'BOT_ADDED',
        101 => 'BOT_RENAMED',
        102 => 'BOT_AVATAR_CHANGED',
        103 => 'BOT_REMOVED',
    ];

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'messages';

    /**
     * The storage format of the model's date columns.
     *
     * @var string
     */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var null|string
     */
    public ?string $temporaryId = null;

    /**
     * @var string
     */
    public $keyType = 'string';

    /**
     * The attributes that can be set with Mass Assignment.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * @var array
     */
    protected $casts = [
        'type' => 'integer',
        'edited' => 'boolean',
        'reacted' => 'boolean',
        'embeds' => 'boolean',
        'extra' => 'array',
    ];

    /**
     * @return BelongsTo|Thread
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(
            Thread::class,
            'thread_id',
            'id'
        );
    }

    /**
     * @return MorphTo|MessengerProvider
     */
    public function owner(): MorphTo
    {
        return $this->morphTo()->withDefault(function (Model $owner) {
            return $owner instanceof Bot
                ? Messenger::getGhostBot()
                : Messenger::getGhostProvider();
        });
    }

    /**
     * @return HasMany|MessageEdit|Collection
     */
    public function edits(): HasMany
    {
        return $this->hasMany(MessageEdit::class);
    }

    /**
     * @return HasMany|MessageReaction|Collection
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    /**
     * @return HasOne
     */
    public function replyTo(): HasOne
    {
        return $this->hasOne(
            Message::class,
            'id',
            'reply_to_id'
        );
    }

    /**
     * Scope a query for only regular text based messages.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeText(Builder $query): Builder
    {
        return $query->where('type', '=', self::MESSAGE);
    }

    /**
     * Scope a query for anything but system messages.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeNonSystem(Builder $query): Builder
    {
        return $query->whereIn('type', self::NonSystemTypes);
    }

    /**
     * Scope a query for only system messages.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->whereNotIn('type', self::NonSystemTypes);
    }

    /**
     * Scope a query for only image messages.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeImage(Builder $query): Builder
    {
        return $query->where('type', '=', self::IMAGE_MESSAGE);
    }

    /**
     * Scope a query for only document messages.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeDocument(Builder $query): Builder
    {
        return $query->where('type', '=', self::DOCUMENT_MESSAGE);
    }

    /**
     * Scope a query for only document messages.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeAudio(Builder $query): Builder
    {
        return $query->where('type', '=', self::AUDIO_MESSAGE);
    }

    /**
     * @return string
     */
    public function getStorageDisk(): string
    {
        return Messenger::getThreadStorage('disk');
    }

    /**
     * @return string
     */
    public function getStorageDirectory(): string
    {
        return Messenger::getThreadStorage('directory')."/$this->thread_id";
    }

    /**
     * @return string
     */
    public function getImagePath(): string
    {
        return "{$this->getStorageDirectory()}/images/$this->body";
    }

    /**
     * @return string
     */
    public function getDocumentPath(): string
    {
        return "{$this->getStorageDirectory()}/documents/$this->body";
    }

    /**
     * @return string
     */
    public function getAudioPath(): string
    {
        return "{$this->getStorageDirectory()}/audio/$this->body";
    }

    /**
     * @return string
     */
    public function getTypeVerbose(): string
    {
        return self::TYPE[$this->type];
    }

    /**
     * @param  string  $size
     * @return string|null
     */
    public function getImageViewRoute(string $size = 'sm'): ?string
    {
        if (! $this->isImage()) {
            return null;
        }

        return Helpers::Route('messenger.threads.gallery.render',
            [
                'thread' => $this->thread_id,
                'message' => $this->id,
                'size' => $size,
                'image' => $this->body,
            ]
        );
    }

    /**
     * @return string|null
     */
    public function getDocumentDownloadRoute(): ?string
    {
        if (! $this->isDocument()) {
            return null;
        }

        return Helpers::Route('messenger.threads.files.download',
            [
                'thread' => $this->thread_id,
                'message' => $this->id,
                'file' => $this->body,
            ]
        );
    }

    /**
     * @return string|null
     */
    public function getAudioDownloadRoute(): ?string
    {
        if (! $this->isAudio()) {
            return null;
        }

        return Helpers::Route('messenger.threads.audio.download',
            [
                'thread' => $this->thread_id,
                'message' => $this->id,
                'audio' => $this->body,
            ]
        );
    }

    /**
     * @return string|null
     */
    public function getEditHistoryRoute(): ?string
    {
        if (! $this->isEdited()) {
            return null;
        }

        return Helpers::Route('api.messenger.threads.messages.history',
            [
                'thread' => $this->thread_id,
                'message' => $this->id,
            ]
        );
    }

    /**
     * @return bool
     */
    public function isFromBot(): bool
    {
        return $this->owner_type === 'bots';
    }

    /**
     * @return bool
     */
    public function notFromBot(): bool
    {
        return ! $this->isFromBot();
    }

    /**
     * @return bool
     */
    public function isEdited(): bool
    {
        return $this->isText() && $this->edited;
    }

    /**
     * @return bool
     */
    public function isReacted(): bool
    {
        return $this->notSystemMessage() && $this->reacted;
    }

    /**
     * @return bool
     */
    public function isText(): bool
    {
        return $this->type === self::MESSAGE;
    }

    /**
     * @return bool
     */
    public function showEmbeds(): bool
    {
        return $this->isText() && $this->embeds;
    }

    /**
     * @return bool
     */
    public function isSystemMessage(): bool
    {
        return ! in_array($this->type, self::NonSystemTypes);
    }

    /**
     * @return bool
     */
    public function notSystemMessage(): bool
    {
        return ! $this->isSystemMessage();
    }

    /**
     * @return bool
     */
    public function isImage(): bool
    {
        return $this->type === self::IMAGE_MESSAGE;
    }

    /**
     * @return bool
     */
    public function isDocument(): bool
    {
        return $this->type === self::DOCUMENT_MESSAGE;
    }

    /**
     * @return bool
     */
    public function isAudio(): bool
    {
        return $this->type === self::AUDIO_MESSAGE;
    }

    /**
     * @return bool
     */
    public function hasTemporaryId(): bool
    {
        return ! is_null($this->temporaryId);
    }

    /**
     * @return string|null
     */
    public function temporaryId(): ?string
    {
        return $this->temporaryId;
    }

    /**
     * @param  string|null  $id
     * @return Message
     */
    public function setTemporaryId(?string $id = null): self
    {
        $this->temporaryId = empty($id) ? null : $id;

        return $this;
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return Factory
     */
    protected static function newFactory(): Factory
    {
        return MessageFactory::new();
    }
}
