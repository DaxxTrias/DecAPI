<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;
use App\Http\Controllers\Controller;

use GuzzleHttp\Client;
use App\Helpers\Helper;
use GameQ\GameQ;
use Log;

use App\IzurviveLocation as Location;
use App\IzurviveLocationSpelling as Spelling;

class DayZController extends Controller
{
    /**
     * The master server API URL.
     *
     * @var string
     */
    private $masterServerUrl = 'http://api.steampowered.com/IGameServersService/GetServerList/v1/?filter=\gamedir\dayz%s&limit=%d&key=%s';

    /**
     * The base API endpoint
     *
     * @return Response
     */
    public function base()
    {
        $base = url('/dayz/');
        $urls = [
            'endpoints' => [
                $base . '/izurvive',
                $base . '/players',
                $base . '/random-server',
                $base . '/status-report',
                $base . '/steam-status-report'
            ]
        ];

        return Helper::json($urls);
    }

    /**
     * Maps location names/searches to their izurvive.com locations
     *
     * @param  Request $request
     * @return Response
     */
    public function izurvive(Request $request)
    {
        $prefix = 'https://www.izurvive.com/';
        $maxResults = intval($request->input('max_results', 1));
        $separator = $request->input('separator', ' | ');
        $zoom = intval($request->input('zoom_level', 6));

        if ($request->exists('list')) {
            $locations = [];
            $spellings = [];

            foreach (Location::all() as $location) {
                $name = $location->name_en;
                $locations[$name] = sprintf('#c=%s;%s;%d', intval($location->latitude), intval($location->longitude), $zoom);
                $spellings[$name] = $location
                                    ->spellings
                                    ->pluck('spelling')
                                    ->all();
            }

            if ($request->wantsJson()) {
                $data = [
                    'url_template' => $prefix . '{location}',
                    'locations' => $locations,
                    'spellings' => $spellings,
                ];

                return Helper::json($data);
            }

            $data = [
                'list' => $locations,
                'spellings' => $spellings,
                'prefix' => $prefix,
                'page' => 'Available Search Locations',
            ];

            return view('dayz.izurvive', $data);
        }

        $search = $request->input('search', null);
        if (empty($search)) {
            return Helper::text('Please specify ?search= or see a list of available locations: ' . route('dayz.izurvive') . '?list');
        }

        $search = urldecode(trim($search));
        $resultsQuery = Spelling::search($search);
        $results = $resultsQuery->get();

        if ($results->isEmpty()) {
            return Helper::text('No results found for search: ' . $search);
        }

        /**
         * Measure Levenshtein distances, then sort them by said distances.
         */
        $results->each(function($result) use ($search) {
            $result->distance = levenshtein($search, $result->spelling);
        });
        $results = $results->sortBy('distance');

        $spellingResults = $results->take($maxResults);
        $spellingResults = $spellingResults->unique('location_id');

        $locations = [];
        foreach ($spellingResults as $spelling) {
            $location = $spelling->location;
            $url = sprintf('%s - %s#c=%d;%d;%d', $location->name_en, $prefix, intval($location->latitude), intval($location->longitude), $zoom);
            $locations[] = str_replace(';', '%3B', $url);
        }

        return Helper::text(implode($separator, $locations));
    }

    /**
     * Queries DayZ servers and returns their current player count
     *
     * @param  Request $request
     * @return Response
     */
    public function players(Request $request)
    {
        $ip = $request->input('ip', null);
        $port = $request->input('port', null);
        $queryPort = $request->input('query', null);

        if (empty($ip) || empty($port)) {
            return Helper::text('[Error: Please specify "ip" AND "port".]');
        }

        $port = (int) $port;
        if (empty($queryPort)) {
            // 24714 is the default "offset" of the query port from the game port
            // e.g. 2302 => 27016
            // Thanks to CrimsonZamboni for this piece of code: https://github.com/tjensen/DayZServerMonitor/blob/a3958888512f1af10bbba0b1749c6889d5f96b98/DayZServerMonitorCore/Server.cs#L30
            $queryPort = $port + 24714;
        }
        else {
            $queryPort = (int) $queryPort;
        }

        $address = sprintf('%s:%s', $ip, $port);

        $query = new GameQ();
        $query->addServer([
            'type' => 'dayz',
            'host' => $address,
            'options' => [
                'query_port' => $queryPort,
            ],
        ]);
        $query->setOption('timeout', 30);

        $result = $query->process();
        if (empty($result[$address])) {
            Log::error('Unable to query gameserver address: ' . $address);
            return Helper::text('[Error: Unable to query server.]');
        }

        $result = $result[$address];
        if (!isset($result['num_players'], $result['max_players'])) {
            return Helper::text('[Error: Unable to retrieve player count.]');
        }

        return Helper::text($result['num_players'] . '/' . $result['max_players']);
    }

    /**
     * Retrieves a random DayZ server.
     *
     * @param Request $request
     * @return Response
     */
    public function randomServer(Request $request)
    {
        $results = $request->input('results', 'ip');
        $filter = '\name_match\*';
        $key = env('STEAM_API_KEY');
        $url = sprintf($this->masterServerUrl, $filter, 5000, $key);

        $client = new Client;
        $response = $client->request('GET', $url, [
            'http_errors' => false
        ]);

        $body = json_decode(utf8_encode($response->getBody()), true);

        if (empty($body['response']['servers']) || count($body['response']['servers']) <= 0) {
            return Helper::text('An error occurred while retrieving server info.');
        }

        $servers = $body['response']['servers'];
        $count = count($servers);
        $serv = $servers[random_int(0, $count - 1)];
        $splitIp = explode(':', $serv['addr']);
        $options = [
            'name' => $serv['name'],
            'ip' => $splitIp[0] . ':' . $serv['gameport'],
            'players' => $serv['players'] . '/' . $serv['max_players']
        ];

        $format = '%s';
        $results = explode(',', $results);
        $text = [];

        foreach ($results as $result) {
            if (!empty($options[$result])) {
                $text[] = $options[$result];
            }
        }

        if (empty($text)) {
            $text = [
                $options['ip']
            ];
        }

        return Helper::text(implode(' - ', $text));
    }

    /**
     * Return the latest DayZ news article, optionally based on search.
     *
     * ! As of October 2020, /status-report and /steam-status-report have been moved to use this endpoint.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return Response
     */
    public function news(Request $request)
    {
        $client = new Client;
        $result = $client->request('GET', 'https://dayz.com/api/article?rowsPerPage=100', [
            'http_errors' => false
        ]);

        $data = json_decode($result->getBody(), true);

        if (empty($data['rows'])) {
            return Helper::text('No DayZ news articles found.');
        }

        $search = $request->input('search', null);
        $rows = $data['rows'];

        $post = $rows[0];
        if (!empty($search)) {
            $searchLowercase = strtolower($search);
            $posts = array_filter($rows, function($post) use ($searchLowercase) {
                $title = strtolower($post['title']);
                return strpos($title, $searchLowercase) !== false;
            });

            if (empty($posts)) {
                return Helper::text(sprintf('No DayZ news articles were found matching the following search: %s', $search));
            }

            $post = reset($posts);
        }

        $title = $post['title'];
        $articleSlug = $post['ArticleCategory']['slug'];
        $postSlug = $post['slug'];
        $output = sprintf('%s - https://dayz.com/article/%s/%s', $title, $articleSlug, $postSlug);
        return Helper::text($output);
    }
}
