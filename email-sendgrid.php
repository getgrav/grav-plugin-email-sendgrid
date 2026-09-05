<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\File\CompiledYamlFile;
use Grav\Common\Grav;
use Grav\Common\Plugin;
use Grav\Plugin\EmailSendgrid\Provider\SendGridProvider;
use RocketTheme\Toolbox\Event\Event;

/**
 * Class EmailSendgridPlugin
 * @package Grav\Plugin
 */
class EmailSendgridPlugin extends Plugin
{
    /** The config file this plugin's settings are saved into. */
    const CONFIG_FILE = 'config://plugins/email-sendgrid.yaml';

    /**
     * @return array
     *
     * The getSubscribedEvents() gives the core a list of events
     *     that the plugin wants to listen to. The key of each
     *     array section is the event that the plugin listens to
     *     and the value (in the form of an array) contains the
     *     callable (or function) as well as the priority. The
     *     higher the number the higher the priority.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onEmailEngines'       => ['onEmailEngines', 0],
            'onEmailTransportDsn'  => ['onEmailTransportDsn', 0],
            'onEmailProviders'     => ['onEmailProviders', 0],
        ];
    }

    /**
     * Composer autoload
     *
     * @return ClassLoader
     */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    public function onEmailEngines(Event $e)
    {
        $engines = $e['engines'];
        $engines->sendgrid = 'Sendgrid';
    }

    public function onEmailTransportDsn(Event $e)
    {
        $engine = $e['engine'];
        if ($engine === 'sendgrid') {
            $options = $this->config->get('plugins.email-sendgrid');
            $transport = $options['transport'] ?? '';
            $dsn = "sendgrid+$transport://";
            $dsn .= urlencode($options['api_key'] ?? '');
            $dsn .= "@default";
            $e['dsn'] = $dsn;
            $e->stopPropagation();
        }
    }

    /**
     * Tell the Email plugin what SendGrid knows about itself.
     *
     * How its delivery webhooks are verified and read, how one is created from
     * the API key already pasted in here, what its DNS has to say, and what its
     * transport does to custom headers. Anything on the site that wants one of
     * those answers asks the Email plugin, which asks this.
     *
     * The event only exists on an Email plugin new enough to own the contract,
     * and that plugin only fires it on PHP 8.1 and above, so nothing under
     * `classes/` is ever loaded on a site where it would not parse.
     *
     * @param Event $e
     * @return void
     */
    public function onEmailProviders(Event $e)
    {
        $registry = $e['providers'];
        if ($registry === null) {
            return;
        }

        $config = $this->config->get('plugins.email-sendgrid');

        $registry->add(new SendGridProvider(
            is_array($config) ? $config : [],
            function ($publicKey) {
                return $this->saveSetting('public_key', $publicKey);
            },
            function ($key, $fallback) {
                return $this->say($key, $fallback);
            }
        ));
    }

    /**
     * Write one setting into this plugin's own config, and mean it for the rest
     * of the request too.
     *
     * The verification key SendGrid mints when signing is turned on is handed
     * over once and never again, so it goes straight in here rather than being
     * printed for somebody to copy. Answering false rather than throwing is
     * deliberate: the caller's fallback is to put the key in the message it
     * shows, which is a worse day than the config file being writable and a far
     * better one than the key being lost.
     *
     * @param string $name
     * @param string $value
     * @return bool
     */
    protected function saveSetting($name, $value)
    {
        try {
            $path = Grav::instance()['locator']->findResource(self::CONFIG_FILE, true, true);
            if (!$path) {
                return false;
            }

            $file = CompiledYamlFile::instance($path);
            $content = (array)$file->content();
            $content[$name] = $value;
            $file->save($content);
            $file->free();

            $this->config->set('plugins.email-sendgrid.' . $name, $value);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * One language string, falling back to the English written in the provider.
     *
     * Grav answers with the key itself when nothing has translated it, so that
     * is what the fallback is looking for.
     *
     * @param string $key
     * @param string $fallback
     * @return string
     */
    protected function say($key, $fallback)
    {
        try {
            $said = Grav::instance()['language']->translate([$key]);
        } catch (\Throwable $e) {
            return $fallback;
        }

        return (!is_string($said) || $said === '' || $said === $key) ? $fallback : $said;
    }
}
