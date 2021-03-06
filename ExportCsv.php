<?php
namespace go1\report_helpers;

use Elasticsearch\Client as ElasticsearchClient;
use Elasticsearch\Common\Exceptions\Missing404Exception;

class ExportCsv
{
    /** @var ElasticsearchClient */
    protected $elasticsearchClient;
    protected $preprocessor;

    public function __construct(ElasticsearchClient $elasticsearchClient, ?PreprocessorInterface $preprocessor = null)
    {
        $this->elasticsearchClient = $elasticsearchClient;
        $this->preprocessor = $preprocessor;
    }

    /**
     * @param array  $fields
     * @param array  $headers
     * @param array  $params
     * @param array  $selectedIds
     * @param array  $excludedIds
     * @param bool   $allSelected
     * @param array  $formatters
     * @return resource
     * @throws \RuntimeException
     */
    public function export($fields, $headers, $params, $selectedIds, $excludedIds, $allSelected, $formatters = [])
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Could not create file descriptor');
        }

        // Write header.
        fputcsv($stream, $headers);

        if (!$allSelected) {
            // Improve performance by not loading all records then filter out.
            $params['body']['query']['bool']['must'][] = [
                'ids' => [
                    'values' => $selectedIds
                ]
            ];
        }

        $params += [
            'scroll' => '30s',
            'size' => 50,
        ];


        $docs = $this->elasticsearchClient->search($params);
        $scrollId = $docs['_scroll_id'];

        while (true) {
            if ($this->preprocessor) {
                $this->preprocessor->process($docs);
            }

            if (count($docs['hits']['hits']) > 0) {
                foreach ($docs['hits']['hits'] as $hit) {
                    if (empty($excludedIds) || !in_array($hit['_id'], $excludedIds)) {
                        $csv = $this->getValues($fields, $hit, $formatters);
                        // Write row.
                        fputcsv($stream, $csv);
                    }
                }
            }
            else {
                if (isset($scrollId)) {
                    try {
                        $this->elasticsearchClient->clearScroll(['scroll_id' => $scrollId]);
                    }
                    catch (Missing404Exception $e) {
                    }
                }
                break;
            }

            $docs = $this->elasticsearchClient->scroll([
                'scroll_id' => $scrollId,
                'scroll'    => '30s'
            ]);

            if (isset($docs['_scroll_id'])) {
                $scrollId = $docs['_scroll_id'];
            }
        }

        fseek($stream, 0);
        return $stream;
    }

    protected function getValues($fields, $hit, $formatters = [])
    {
        $values = [];
        foreach ($fields as $key) {
            if (isset($formatters[$key]) && is_callable($formatters[$key])) {
                $values[] = $formatters[$key]($hit);
            }
            else {
                if (isset($formatters[$key]) && is_string($formatters[$key])) {
                    $value = array_get($hit['_source'], $formatters[$key]);
                }
                elseif (isset($hit['_source'][$key])) {
                    $value = $hit['_source'][$key];
                }
                else {
                    $value = '';
                }
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                $values[] = $value;
            }
        }
        return $values;
    }
}
