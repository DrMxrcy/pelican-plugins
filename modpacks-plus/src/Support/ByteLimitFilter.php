<?php

namespace DrMxrcy\ModpacksPlus\Support;

/**
 * A write filter on a download's sink file (plan 32 security review): once more than `limit`
 * bytes have been written the write fails, whatever the HTTP handler does and however often
 * curl calls the progress callback. `state` (an object) records that the limit was crossed so
 * the caller can report it.
 *
 * Use attach() rather than registering it yourself.
 */
final class ByteLimitFilter extends \php_user_filter
{
    public const NAME = 'modpacks-plus.byte-limit';

    private int $written = 0;

    /**
     * Attaches the filter to $handle (opened for writing) and returns the state object whose
     * `exceeded` property turns true once more than $limit bytes were offered.
     */
    public static function attach(mixed $handle, int $limit): \stdClass
    {
        if (!in_array(self::NAME, stream_get_filters(), true)) {
            stream_filter_register(self::NAME, self::class);
        }

        $state = new \stdClass();
        $state->exceeded = false;
        if (!is_resource($handle) || stream_filter_append($handle, self::NAME, STREAM_FILTER_WRITE, ['limit' => max(0, $limit), 'state' => $state]) === false) {
            throw new \RuntimeException('byte limit filter could not be attached');
        }

        return $state;
    }

    /**
     * @param  resource  $in
     * @param  resource  $out
     * @param  int  $consumed
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        $params = is_array($this->params) ? $this->params : [];
        $limit = is_int($params['limit'] ?? null) ? $params['limit'] : 0;
        $state = $params['state'] ?? null;

        while ($bucket = stream_bucket_make_writeable($in)) {
            $this->written += $bucket->datalen;
            if ($this->written > $limit) {
                if ($state instanceof \stdClass) {
                    $state->exceeded = true;
                }

                return PSFS_ERR_FATAL;
            }

            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
