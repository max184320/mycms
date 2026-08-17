<?php

namespace DleSitemap;

use DleSitemap\Sitemap\NewsBuilder;
use DleSitemap\Sitemap\SitemapBuilder;
use DleSitemap\Sitemap\SitemapIndex;

class Helper
{
	public static function escapeUrl(string $url): string
	{
		$url = str_replace(['&amp;', '&apos;', '&quot;'], ['&', "'", '"'], $url);
		$url = parse_url($url);

		if (!is_array($url) || empty($url['scheme']) || empty($url['host'])) {
			return '';
		}

		$url['path'] = $url['path'] ?? '';
		$url['query'] = isset($url['query']) && $url['query'] !== '' ? '?' . $url['query'] : '';

		return str_replace(
			['&', "'", '"', '>', '<'],
			['&amp;', '&apos;', '&quot;', '&gt;', '&lt;'],
			$url['scheme'] . '://' . $url['host'] . $url['path'] . $url['query']
		);
	}
}

class Sitemap
{
	protected array $options = [
		'save_path'    => null,
		'index_name'   => 'sitemap.xml',
		'sitemaps_url' => null,
	];

	protected array $sitemaps = [];
	protected string $domain;

	public function __construct(string $domain, ?array $options = null)
	{
		$this->domain = $domain;

		if ($options !== null) {
			$this->options = array_merge($this->options, $options);
		}
	}

	public function setSavePath(string $path): self
	{
		$this->options['save_path'] = $path;

		return $this;
	}

	public function setIndexName(string $name): self
	{
		$this->options['index_name'] = $name;

		return $this;
	}

	public function setSitemapsUrl(string $url): self
	{
		$this->options['sitemaps_url'] = $url;

		return $this;
	}

	public function links($options, callable $func): self
	{
		$options = $this->normalizeOptions($options);

		return $this->build(new SitemapBuilder($this->domain, $options), $options, $func);
	}

	public function news($options, callable $func): self
	{
		return $this->build(new NewsBuilder($this->domain, $this->normalizeOptions($options)), $this->normalizeOptions($options), $func);
	}

	public function saveTo(string $path): bool
	{
		return SitemapIndex::build($this->options['index_name'], $path, $this->options['sitemaps_url'] ?? $this->domain, $this->sitemaps);
	}

	public function save(): bool
	{
		if (!is_string($this->options['save_path']) || $this->options['save_path'] === '') {
			throw new \RuntimeException('Invalid or missing save_path option');
		}

		return $this->saveTo($this->options['save_path']);
	}

	protected function build(SitemapBuilder $builder, array $options, callable $func): self
	{
		if (isset($this->sitemaps[$options['name']])) {
			throw new \RuntimeException("The sitemap {$options['name']} already registred!");
		}

		$func($builder);
		$this->sitemaps[$options['name']] = $builder->saveTemp();

		return $this;
	}

	protected function normalizeOptions($options): array
	{
		if (is_string($options)) {
			$options = ['name' => $options];
		}

		if (!is_array($options) || empty($options['name']) || !is_string($options['name'])) {
			throw new \RuntimeException('Sitemap name is required');
		}

		return $options;
	}
}

namespace DleSitemap\Sitemap;

use DleSitemap\Helper;
use SimpleXMLElement;

class SitemapBuilder
{
	protected const IMAGE_NS = 'http://www.google.com/schemas/sitemap-image/1.1';
	protected const NEWS_NS = 'http://www.google.com/schemas/sitemap-news/0.9';
	protected const VIDEO_NS = 'http://www.google.com/schemas/sitemap-video/1.1';
	protected const XHTML_NS = 'http://www.w3.org/1999/xhtml';

	protected array $validation = [
		'video' => ['thumbnail_loc', 'title', 'description'],
		'freq' => ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'],
	];

	protected array $url = [];
	protected string $domain;
	protected int $max = 45000;
	protected SimpleXMLElement $doc;
	protected array $options = [
		'images'    => false,
		'videos'    => false,
		'localized' => false,
	];

	public function __construct(string $domain, ?array $options = null, string $ns = '')
	{
		$this->domain = $domain;
		$this->options = array_merge($this->options, $options ?? []);

		$urlset = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';

		if (!empty($this->options['images'])) {
			$urlset .= ' xmlns:image="' . static::IMAGE_NS . '"';
		}

		if (!empty($this->options['videos'])) {
			$urlset .= ' xmlns:video="' . static::VIDEO_NS . '"';
		}

		if (!empty($this->options['localized'])) {
			$urlset .= ' xmlns:xhtml="' . static::XHTML_NS . '"';
		}

		$this->doc = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8" ?>' . $urlset . $ns . '/>');
	}

	public function loc(string $path): self
	{
		return $this->append()->url($this->domain . $path);
	}

	public function url(string $url): self
	{
		if ($this->max <= 0) {
			throw new \RuntimeException('The maximum urls has been exhausted');
		}

		$escaped = Helper::escapeUrl($url);

		if ($escaped === '') {
			throw new \RuntimeException('Invalid sitemap url');
		}

		$this->url['loc'] = $escaped;

		return $this;
	}

	public function alternate(string $path, string $lang): self
	{
		if ($path !== '' && $path[0] !== '/') {
			$path = '/' . $path;
		}

		$url = Helper::escapeUrl($this->domain . $path);

		if ($url === '') {
			throw new \RuntimeException('Invalid alternate sitemap url');
		}

		$this->url['alternate'][] = [$url, $lang];

		return $this;
	}

	public function append(): self
	{
		if (empty($this->url)) {
			return $this;
		}

		$url = $this->doc->addChild('url');

		foreach ($this->url as $name => $value) {
			if ($name === 'image') {
				foreach ($value as $imageOptions) {
					$child = $url->addChild('image:image', null, static::IMAGE_NS);

					foreach ($imageOptions as $optionName => $optionValue) {
						$child->addChild('image:' . $optionName, $optionValue);
					}
				}

				continue;
			}

			if ($name === 'video') {
				foreach ($value as $videoOptions) {
					$child = $url->addChild('video:video', null, static::VIDEO_NS);

					foreach ($videoOptions as $optionName => $optionValue) {
						$child->addChild('video:' . $optionName, $optionValue);
					}
				}

				continue;
			}

			if ($name === 'news') {
				$child = $url->addChild('news:news', null, static::NEWS_NS);
				$publication = $child->addChild('news:publication');
				$publication->addChild('news:name', $value['name']);
				$publication->addChild('news:language', $value['language']);
				unset($value['name'], $value['language']);

				foreach ($value as $optionName => $optionValue) {
					$child->addChild('news:' . $optionName, $optionValue);
				}

				continue;
			}

			if ($name === 'alternate') {
				foreach ($value as $alternateOptions) {
					$child = $url->addChild('xhtml:link', null, static::XHTML_NS);
					$child->addAttribute('rel', 'alternate');
					$child->addAttribute('href', $alternateOptions[0]);
					$child->addAttribute('hreflang', $alternateOptions[1]);
				}

				continue;
			}

			$url->addChild($name, $value);
		}

		$this->max--;
		$this->url = [];

		return $this;
	}

	public function lastMod($date): self
	{
		$this->url['lastmod'] = $this->parseDate($date);

		return $this;
	}

	public function image(string $imageUrl, array $options = []): self
	{
		if (empty($this->options['images'])) {
			throw new \RuntimeException('Before set a image, enable images option');
		}

		$options['loc'] = $this->getByRelativeUrl($imageUrl);
		$this->url['image'][] = $options;

		return $this;
	}

	public function video(string $title, array $options = []): self
	{
		if (empty($this->options['videos'])) {
			throw new \RuntimeException('Before set a video, enable videos option first');
		}

		$options['title'] = $title;

		if (isset($options['thumbnail'])) {
			$options['thumbnail_loc'] = $options['thumbnail'];
			unset($options['thumbnail']);
		}

		foreach ($this->validation['video'] as $requiredOption) {
			if (!isset($options[$requiredOption])) {
				throw new \RuntimeException("video {$requiredOption} options is required");
			}
		}

		if (!isset($options['content_loc']) && !isset($options['player_loc'])) {
			throw new \RuntimeException('Raw video url content_loc or player_loc is required');
		}

		foreach (['thumbnail_loc', 'content_loc', 'player_loc'] as $urlField) {
			if (isset($options[$urlField]) && is_string($options[$urlField]) && strpos($options[$urlField], '://') === false) {
				$options[$urlField] = $this->getByRelativeUrl($options[$urlField]);
			}
		}

		$this->url['video'][] = $options;

		return $this;
	}

	public function changeFreq(string $freq): self
	{
		if (!in_array($freq, $this->validation['freq'], true)) {
			throw new \RuntimeException('changefreq value not valid');
		}

		$this->url['changefreq'] = $freq;

		return $this;
	}

	public function freq(string $freq): self
	{
		return $this->changeFreq($freq);
	}

	public function priority(string $priority): self
	{
		$this->url['priority'] = $priority;

		return $this;
	}

	public function saveTo(string $path): bool
	{
		return (bool)$this->append()->doc->asXML($path);
	}

	public function saveTemp(): string
	{
		$directory = defined('ENGINE_DIR') && is_dir(ENGINE_DIR . '/cache') && is_writable(ENGINE_DIR . '/cache') ? ENGINE_DIR . '/cache' : sys_get_temp_dir();
		$temp = tempnam($directory, 'dle_sitemap_');

		if ($temp && $this->saveTo($temp)) {
			return $temp;
		}

		throw new \RuntimeException('Saving sitemap to temp failed');
	}

	protected function getByRelativeUrl(string $url): string
	{
		if (strpos($url, '://') === false) {
			$url = $this->domain . ($url[0] !== '/' ? $url . '/' : $url);
		}

		return $url;
	}

	protected function parseDate($date): string
	{
		if (!is_int($date)) {
			$date = strtotime((string)$date);
		}

		return date('c', (int)$date);
	}
}

class NewsBuilder extends SitemapBuilder
{
	private array $publication = ['name' => null, 'lang' => null];

	public function __construct(string $domain, ?array $options = null, string $ns = '')
	{
		parent::__construct($domain, $options, $ns . ' xmlns:news="' . static::NEWS_NS . '"');
	}

	public function setPublication(string $name, string $lang): self
	{
		$this->publication = [
			'name' => $name,
			'lang' => $lang,
		];

		return $this;
	}

	public function news(array $options): self
	{
		$options['name'] = $options['name'] ?? $this->publication['name'];
		$options['language'] = $options['language'] ?? $this->publication['lang'];

		if (!isset($options['name'], $options['language'], $options['publication_date'], $options['title'])) {
			throw new \RuntimeException('News map require: name, language, publication_date and title');
		}

		$this->url['news'] = $options;

		return $this;
	}
}

class SitemapIndex
{
	public static function build(string $index, string $path, string $url, array $maps): bool
	{
		$path = rtrim($path, '/') . '/';

		if (!is_dir($path) || !is_writable($path)) {
			throw new \RuntimeException("The path {$path} is not writable");
		}

		$url = rtrim($url, '/') . '/';
		$dom = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8" ?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"/>');

		foreach ($maps as $name => $file) {
			$destination = $path . $name;

			if (!@rename($file, $destination)) {
				if (!@copy($file, $destination) || !@unlink($file)) {
					throw new \RuntimeException("Moving the file {$destination} failed!");
				}
			}

			@chmod($destination, 0666);

			$sitemap = $dom->addChild('sitemap');
			$sitemap->addChild('loc', $url . $name);
			$sitemap->addChild('lastmod', date('c'));
		}
		
		$indexFile = $path . $index;
		$result = (bool)$dom->asXML($indexFile);

		if ($result) {
			@chmod($indexFile, 0666);
		}

		return $result;
	}
}
