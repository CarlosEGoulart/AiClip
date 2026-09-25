<?php

/*
|--------------------------------------------------------------------------
| Recording entry-point patches for the FakeRankingProvider purity test
|--------------------------------------------------------------------------
|
| PHP without uopz/runkit cannot replace built-ins globally, so these
| namespace-local function shadows intercept process/network/media-file
| calls made unqualified from the FakeRankingProvider's own namespace
| (App\Services) and from Symfony\Component\Process. Every interception is
| RECORDED and then delegated to the real implementation, so the patches are
| safe for the whole test process run (including tests that legitimately
| spawn real Symfony processes) while still proving by assertion that the
| fake provider performed no such access. Database access is guarded
| separately in the test via a non-existent default driver, which makes any
| connection attempt throw.
|
| No code outside the FakeRankingProvider purity test should rely on these
| counters.
*/

namespace App\Services {

    function proc_open(...$args)
    {
        $GLOBALS['__fake_provider_io']['process']++;

        return \proc_open(...$args);
    }

    function shell_exec(...$args)
    {
        $GLOBALS['__fake_provider_io']['process']++;

        return \shell_exec(...$args);
    }

    function popen(...$args)
    {
        $GLOBALS['__fake_provider_io']['process']++;

        return \popen(...$args);
    }

    function exec(...$args)
    {
        $GLOBALS['__fake_provider_io']['process']++;

        return \exec(...$args);
    }

    function fsockopen(...$args)
    {
        $GLOBALS['__fake_provider_io']['network']++;

        return \fsockopen(...$args);
    }

    function pfsockopen(...$args)
    {
        $GLOBALS['__fake_provider_io']['network']++;

        return \pfsockopen(...$args);
    }

    function stream_socket_client(...$args)
    {
        $GLOBALS['__fake_provider_io']['network']++;

        return \stream_socket_client(...$args);
    }

    function curl_init(...$args)
    {
        $GLOBALS['__fake_provider_io']['network']++;

        return \curl_init(...$args);
    }

    function fopen(...$args)
    {
        $GLOBALS['__fake_provider_io']['media_file']++;

        return \fopen(...$args);
    }

    function file_get_contents(...$args)
    {
        $GLOBALS['__fake_provider_io']['media_file']++;

        return \file_get_contents(...$args);
    }

    function fread(...$args)
    {
        $GLOBALS['__fake_provider_io']['media_file']++;

        return \fread(...$args);
    }

    function readfile(...$args)
    {
        $GLOBALS['__fake_provider_io']['media_file']++;

        return \readfile(...$args);
    }

    function file(...$args)
    {
        $GLOBALS['__fake_provider_io']['media_file']++;

        return \file(...$args);
    }

    function scandir(...$args)
    {
        $GLOBALS['__fake_provider_io']['media_file']++;

        return \scandir(...$args);
    }

    function opendir(...$args)
    {
        $GLOBALS['__fake_provider_io']['media_file']++;

        return \opendir(...$args);
    }
}

namespace Symfony\Component\Process {

    function proc_open($commandline, &$descriptors, &$pipes = null, $cwd = null, $env = null, $other_options = null)
    {
        $GLOBALS['__fake_provider_io']['process']++;

        return \proc_open($commandline, $descriptors, $pipes, $cwd, $env, $other_options);
    }
}
