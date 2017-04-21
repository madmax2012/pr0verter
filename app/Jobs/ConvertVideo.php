<?php

namespace App\Jobs;

use FFMpeg\Coordinate\Dimension;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use FFMpeg\Format\Video\X264;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class ConvertVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var integer
     */
    private $maxDuration;

    /**
     * @var integer
     */
    private $duration;
    /**
     * @var integer
     */
    private $status;

    /**
     * @var array
     */
    private $params;

    /**
     * @var string
     */
    private $loc;

    /**
     * @var string
     */
    private $name;

    /**
     * @var bool
     */
    private $sound;

    /**
     * @var bool
     */
    private $res;

    /**
     * @var bool
     */
    private $limit;

    /**
     * @var float
     */
    private $px;

    /**
     * @var float
     */
    private $py;

    /**
     * Create a new job instance.
     *
     * @param $loc
     * @param $name
     * @param $sound
     * @param $res
     * @param $limit
     * @return void
     */

    public function __construct($loc, $name, $sound, $res, $limit)
    {
        $this->loc = $loc;
        $this->name = $name;
        $this->sound = $sound;
        $this->res = $res;
        $this->limit = $limit;

        $this->maxDuration = env('VIDEO_MAX_DURATION_IN_SECONDS', 179);

        $this->params = [
            'ffmpeg.binaries' => env('FFMPEG_BIN', '/usr/local/bin/ffmpeg'),
            'ffmpeg.threads' => env('FFMPEG_THREADS', 12),
            'ffprobe.binaries' => env('FFMPEG_PROBE_BIN', '/usr/local/bin/ffprobe'),
            'timeout' => env('FFMPEG_TIMEOUT', 3600)];
    }

    /**
     * Execute the job.
     *
     * converts video
     * short description of parameters
     * -t: set max video length
     * -profile:v baseline -level 3.0: pr0gramm only supports baseline lv 3.0
     * -preset: sets conversion speed
     * -fs: ffmpeg cuts on this size
     *
     * @return void
     */
    public function handle()
    {
        $ffprobe = FFProbe::create($this->params);
        $ffmpeg = FFMpeg::create($this->params);


        $this->duration = $ffprobe
            ->format($this->loc . '/' . $this->name)// extracts file informations
            ->get('duration');             // returns the duration property

        $video = $ffmpeg->open($this->loc . '/' . $this->name);

        $video->filters()->custom("-t $this->maxDuration");
        $video->filters()->custom("-profile:v baseline -level 3.0");
        $video->filters()->custom("-preset normal");
        $video->filters()->custom("-fs " . $this->limit * 8192 . "k");

        if (!$this->res) {
            $this->getAutoResolution();
            $video->filters()->resize(new Dimension($this->px, $this->py));
        }
        $format = new X264();
        !$this->sound ?: $format->setAudioCodec('aac');
        switch ($this->sound) {
            case 0:
                $video->filters()->custom("-an");
                break;
            case 1:
                $format->setAudioKiloBitrate(60); // test value
                break;
            case 2:
                $format->setAudioKiloBitrate(120);
                break;
            case 3:
                $format->setAudioKiloBitrate(190); // test value
                break;
        }

        $format->setPasses(2);
        $format->setKiloBitrate($this->getBitrate());

        $format->on('progress', function ($video, $format, $percentage) {
            DB::table('data')->where('guid', $this->name)->update(['progress' => $percentage]);
        });

        if($video->save($format, $this->loc . '/public/' . $this->name . '.mp4')) {
            DB::table('data')->where('guid', $this->name)->update(['progress' => 100]);
        }
    }

    function getBitrate()
    {

        $this->duration = min($this->duration, $this->maxDuration);

        $bitrate = ($this->limit * 8192) / $this->duration;

        !$this->sound ? : $bitrate -= $this->sound;
        return $bitrate . 'k';
    }

    function getAutoResolution()
    {
        if ($this->duration > 30 && $this->duration < 60 && $this->px >= 480) {
            if ($this->px * (16 / 9) === $this->py) {
                $this->px = 576;
                $this->py = 1024;
            } else {
                if ($this->px > 480 && $this->py < 720) {
                    $this->px /= 1.5;
                    $this->py /= 1.5;
                } else if ($this->px > 720) {
                    $this->px /= 2;
                    $this->py /= 2;
                }
            }
        }
        if ($this->duration > 60 && $this->duration < 110 && $this->px > 480) {
            if ($this->px * (16 / 9) === $this->py) {
                $this->px = 480;
                $this->py = 854;
            } else {
                if ($this->px > 480 && $this->py < 720) {
                    $this->px /= 1.6; // WARNGING: Test Values
                    $this->py /= 1.6; //
                } else if ($this->px > 720) {
                    $this->px /= 2.1; //
                    $this->py /= 2.1; //
                }
            }
        }
        if ($this->duration > 110 && $this->px > 480) {
            if ($this->px * (16 / 9) === $this->py) {
                $this->px = 432;
                $this->py = 768;
            } else {
                if ($this->px > 480 && $this->py < 720) {
                    $this->px /= 1.9; // test
                    $this->py /= 1.9; //
                } else if ($this->px > 720) {
                    $this->px /= 2.5; //
                    $this->py /= 2.5; //
                }
            }
        }
        $this->px = round($this->px);
        $this->py = round($this->py);

        // resolution has to be even
        if ($this->px % 2 != 0) {
            $this->px++;
        }
        if ($this->py % 2 != 0) {
            $this->py++;
        }
    }
}