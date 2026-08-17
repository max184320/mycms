<?php

namespace DleSeo;

class Helper
{
	public static string $encoding = 'UTF-8';

	public static function escape(string $text): string
	{
		$text = htmlspecialchars($text, ENT_COMPAT | ENT_HTML5, static::$encoding, false);

		return str_replace(['{', '}', '[', ']'], ['&#123;', '&#125;', '&#91;', '&#93;'], $text);
	}
}

class Schema implements \JsonSerializable
{
	protected array $things = [];

	public function __construct(...$things)
	{
		$this->things = $things;
	}

	public function add($thing): self
	{
		$this->things[] = $thing;

		return $this;
	}

	public function jsonSerialize(): array
	{
		return [
			'@context' => 'https://schema.org',
			'@graph'   => $this->things,
		];
	}

	public function __toString(): string
	{
		return '<script type="application/ld+json">' . json_encode($this->jsonSerialize()) . '</script>';
	}
}

class MetaTags
{
	protected array $tags = [];
	protected array $twitterTags = [];
	protected array $openGraphTags = [];
	public ?string $title = null;

	public function __construct(array $tags = [])
	{
		foreach ($tags as $k => $v) {
			if (method_exists(static::class, $k)) {
				$this->$k($v);

				continue;
			}

			$this->meta($k, $v);
		}
	}

	public function title(string $title): MetaTags
	{
		$this->title = Helper::escape($title);

		return $this->og('title', $title)->twitter('title', $title);
	}

	public function description(string $desc): MetaTags
	{
		return $this->meta('description', $desc)->og('description', $desc)->twitter('description', $desc);
	}

	public function mobile(string $url): MetaTags
	{
		return $this->push('link', [
			'href'  => $url,
			'rel'   => 'alternate',
			'media' => 'only screen and (max-width: 640px)',
		]);
	}

	public function robots(string $options, string $botName = 'robots'): MetaTags
	{
		return $this->meta($botName, $options);
	}

	public function amp(string $url): MetaTags
	{
		return $this->push('link', [
			'rel'  => 'amphtml',
			'href' => $url,
		]);
	}

	public function canonical(string $url): MetaTags
	{
		return $this->push('link', [
			'rel'  => 'canonical',
			'href' => $url,
		]);
	}

	public function url(string $url): MetaTags
	{
		return $this->og('url', $url)->twitter('url', $url);
	}

	public function hreflang(string $lang, string $url): MetaTags
	{
		return $this->push('link', [
			'rel'       => 'alternate',
			'href'      => $url,
			'hreflang'  => $lang,
		]);
	}

	public function meta(string $name, string $value): MetaTags
	{
		return $this->push('meta', [
			'name'    => $name,
			'content' => $value,
		]);
	}

	public function push(string $name, array $attrs): MetaTags
	{
		$this->tags[] = [$name, $attrs];

		return $this;
	}

	public function og(string $name, string $value): MetaTags
	{
		$this->openGraphTags[] = ['meta', ['property' => "og:{$name}", 'content' => $value]];

		return $this;
	}

	public function twitter(string $name, string $value): MetaTags
	{
		$this->twitterTags[] = ['meta', ['property' => "twitter:{$name}", 'content' => $value]];

		return $this;
	}

	public function shortlink(string $url): MetaTags
	{
		return $this->push('link', [
			'rel'  => 'shortlink',
			'href' => $url,
		]);
	}

	public function image(string $url, string $card = 'summary_large_image'): MetaTags
	{
		if (stripos($url, 'http') !== 0) {
			$protocol = isSSL() ? 'https://' : 'http://';
			$host = $_SERVER['HTTP_HOST'] ?? '';

			if (strpos($url, '//') === 0) {
				$url = $protocol . ltrim($url, '/');
			} elseif ($host) {
				$url = $protocol . $host . '/' . ltrim($url, '/');
			}
		}

		return $this->og('image', $url)->twitter('card', $card)->twitter('image', $url);
	}

	public function build(array $tags): string
	{
		$out = '';

		foreach ($tags as $tag) {
			$out .= "\n<{$tag[0]}";

			foreach ($tag[1] as $a => $v) {
				$out .= ' ' . $a . '="' . Helper::escape($v) . '"';
			}

			$out .= '>';
		}

		return $out;
	}

	public function __toString(): string
	{
		$title = '';

		if ($this->title !== null) {
			$title = "<title>{$this->title}</title>";
		}

		return $title . $this->build($this->tags) . $this->build($this->twitterTags) . $this->build($this->openGraphTags);
	}
}

class Indexing
{
	protected string $host;
	protected array $keys = [];
	protected int $timeout = 3;

	public function __construct(string $host, array $keys)
	{
		$this->host = $host;
		$this->keys = $keys;
	}

	public function indexUrl(string $url): array
	{
		return $this->indexUrls([$url]);
	}

	public function indexUrls(array $urls): array
	{
		$accepted = [];

		foreach ($this->keys as $engine => $key) {
			$accepted[$engine] = $this->index((string)$engine, (string)$key, $urls);
		}

		return $accepted;
	}

	protected function index(string $engine, string $apiKey, array $urls)
	{
		if (!$this->isValidHost($engine)) {
			return 503;
		}

		$payload = json_encode([
			'host'    => $this->host,
			'key'     => $apiKey,
			'urlList' => $urls,
		]);

		if ($payload === false) {
			return 503;
		}

		$code = $this->request($engine, $payload);

		if (!$code) {
			$code = 503;
		}

		return $code === 200 ? true : $code;
	}

	protected function request(string $engine, string $payload): int
	{
		if (!function_exists('curl_init')) {
			return 503;
		}

		$ch = curl_init('https://' . $engine . '/indexnow');

		if ($ch === false) {
			return 503;
		}

		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['content-type: application/json']);
		curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

		curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

		return $code ?: 503;
	}

	protected function isValidHost(string $host): bool
	{
		if ($host === 'localhost') {
			return true;
		}

		if (filter_var($host, FILTER_VALIDATE_IP)) {
			return true;
		}

		return (bool)preg_match('/^(?=.{1,253}$)(?!-)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+[a-z0-9-]{2,63}$/i', $host);
	}
}

namespace DleSeo\Schema;

class Thing implements \JsonSerializable
{
	protected string $type;
	protected bool $need_context;
	protected array $data = [];
	public $context = null;

	public function __construct(string $type, array $data = [], bool $need_context = true)
	{
		$this->data = $data;
		$this->type = $type;
		$this->need_context = $need_context;
	}

	public function __get(string $name)
	{
		return $this->data[$name] ?? null;
	}

	public function __set(string $name, $value): void
	{
		$this->data[$name] = $value;
	}

	public function jsonSerialize(): array
	{
		$data = [];

		if ($this->type) {
			$data['@type'] = $this->type;
		}

		if ($this->need_context) {
			$data['@context'] = $this->context ?? 'https://schema.org/';
		}

		return count($data) ? array_merge($data, $this->data) : $this->data;
	}

	public function __toString(): string
	{
		return '<script type="application/ld+json">' . json_encode($this) . '</script>';
	}
}
