<?php

namespace Ahmedkandel\NovaS3MultipartUpload\Http\Controllers;

use Ahmedkandel\NovaS3MultipartUpload\NovaS3MultipartUpload;
use Aws\S3\S3Client;
use Illuminate\Support\Str;
use Laravel\Nova\Http\Requests\NovaRequest;

class UploadController
{
    /**
     * Resource tool instance.
     *
     * @var \Ahmedkandel\NovaS3MultipartUpload\NovaS3MultipartUpload
     */
    private $tool;

    /**
     * S3 client instance.
     *
     * @var \Aws\S3\S3Client
     */
    private $s3Client;

    /**
     * Set preflight response header to allow X-CSRF-TOKEN in Uppy request headers.
     *
     * @return void
     */
    public function preflightHeader()
    {
        header('Access-Control-Allow-Headers: Authorization, Origin, Content-Type, Accept, X-CSRF-TOKEN');
    }

    /**
     * Retrieve resource tool and Authorize then create S3Client..
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return void
     */
    private function init($request)
    {
        $resource = $request->findResourceOrFail();

        $this->tool = collect($resource->availablePanelsForDetail($request, $resource))
            ->whereInstanceOf(NovaS3MultipartUpload::class)
            ->firstWhere('attribute', $request->route('field'));

        abort_unless($this->tool, 404);

        abort_unless($this->tool->element->authorizedToSee($request), 403);

        abort_unless($this->tool->canUpload, 403);

        $this->s3Client = new S3Client([
            'credentials' => [
                'key'    => config("filesystems.disks.{$this->tool->disk}.key"),
                'secret' => config("filesystems.disks.{$this->tool->disk}.secret"),
            ],
            'region' => config("filesystems.disks.{$this->tool->disk}.region"),
            'version' => 'latest',
        ]);
    }

    /**
     * Generate file storage key
     *
     * @param string $fileName
     * @return string
     */
    private function generateFileKey($fileName)
    {
        $path = $this->tool->storagePath ? $this->tool->storagePath . '/' : '';
        $name = $this->tool->keepOriginalName ? pathinfo($fileName, PATHINFO_FILENAME) : (string) Str::uuid();
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);

        return $path . $name . ($extension ? '.' . $extension : '');
    }

    /**
     * Create an S3 multipart upload. With this, files can be uploaded in chunks of 5MB+ each.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    public function createMultipartUpload(NovaRequest $request)
    {
        $this->init($request);

        $data = $this->s3Client->createMultipartUpload([
            'Bucket' => config("filesystems.disks.{$this->tool->disk}.bucket"),
            'Key' => $this->generateFileKey($request->input('filename')),
            'ContentType' => $request->input('type'),
            'Metadata' => $request->input('metadata'),
        ]);

        return [
            'key' => $data['Key'],
            'uploadId' => $data['UploadId'],
        ];
    }

    /**
     * List parts that have been fully uploaded so far.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    public function getUploadedParts(NovaRequest $request)
    {
        $this->init($request);

        $data = $this->s3Client->listParts([
            'Bucket' => config("filesystems.disks.{$this->tool->disk}.bucket"),
            'Key' => $request->query('key'),
            'UploadId' => $request->route('uploadId'),
            'PartNumberMarker' => 0,
        ]);

        return $data['Parts'] ?? [];
    }

    /**
     * Get presigned url for specific part.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @param  int  $partNumber
     * @return string
     */
    public function getSignedUrl($request, $partNumber)
    {
        $command = $this->s3Client->getCommand('UploadPart', [
            'Bucket' => config("filesystems.disks.{$this->tool->disk}.bucket"),
            'Key' => $request->query('key'),
            'UploadId' => $request->route('uploadId'),
            'PartNumber' => $partNumber,
        ]);
        $s3Request = $this->s3Client->createPresignedRequest($command, '+20 minutes');

        return (string) $s3Request->getUri();
    }

    /**
     * Get parameters for uploading a batch of parts.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    public function batchSignPartsUpload(NovaRequest $request)
    {
        $this->init($request);

        $partNumbers = explode(',', $request->query('partNumbers'));

        $data = [];

        foreach ($partNumbers as $partNumber) {
            $data[(string) $partNumber] = $this->getSignedUrl($request, $partNumber);
        }

        return [
            'presignedUrls' => $data,
        ];
    }

    /**
     * Get parameters for uploading one part.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    public function signPartUpload(NovaRequest $request)
    {
        $this->init($request);

        $url = $this->getSignedUrl($request, $request->route('partNumber'));

        return [
            'url' => $url,
        ];
    }

    /**
     * Complete a multipart upload, combining all the parts into a single object in the S3 bucket.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    public function completeMultipartUpload(NovaRequest $request)
    {
        $this->init($request);

        $data = $this->s3Client->completeMultipartUpload([
            'Bucket' => config("filesystems.disks.{$this->tool->disk}.bucket"),
            'Key' => $request->query('key'),
            'UploadId' => $request->route('uploadId'),
            'MultipartUpload' => [
                'Parts' => $request->input('parts'),
            ],
        ]);

        return [
            'location' => $data['Location'],
        ];
    }

    /**
     * Abort a multipart upload, deleting already uploaded parts.
     *
     * @param  \Laravel\Nova\Http\Requests\NovaRequest  $request
     * @return array
     */
    public function abortMultipartUpload(NovaRequest $request)
    {
        $this->init($request);

        $this->s3Client->abortMultipartUpload([
            'Bucket' => config("filesystems.disks.{$this->tool->disk}.bucket"),
            'Key' => $request->query('key'),
            'UploadId' => $request->route('uploadId'),
        ]);

        return [];
    }
}
