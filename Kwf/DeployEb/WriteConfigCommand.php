<?php

namespace Kwf\DeployEb;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Aws\ElasticBeanstalk\ElasticBeanstalkClient;
use Aws\S3\S3Client;
use Symfony\Component\Yaml\Yaml;

class WriteConfigCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('write-config')
            ->setDescription('Write config.local.ini based on environment variables. Run on ec2 instance startup.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $hostname = file_get_contents("http://instance-data/latest/meta-data/hostname");
        if (!$hostname) {
            $output->getErrorOutput()->writeln("<error>Couldn't get machine hostname. This script should be run on ec2 instance.</error>");
            return 1;
        }

        if (isset($_ENV['KWF_CONFIG_SECTION'])) {
            file_put_contents('config_section', $_ENV['KWF_CONFIG_SECTION']);
        }

        if (!isset($_ENV['RDS_HOSTNAME'])) {
            $output->getErrorOutput()->writeln("<error>Couldn't get RDS_HOSTNAME, make sure you configured RDS correclty.</error>");
            return 1;
        }

        $config = "[production]
        database.web.host = {$_ENV['RDS_HOSTNAME']}
        database.web.port = {$_ENV['RDS_PORT']}
        database.web.user = {$_ENV['RDS_USERNAME']}
        database.web.password = {$_ENV['RDS_PASSWORD']}
        database.web.name = {$_ENV['RDS_DB_NAME']}

        server.baseUrl = \"\"
        server.domain = $hostname
        server.redirectToDomain = false
        ";

        if (isset($_ENV['S3_UPLOADS_BUCKET'])) {
            $config .= "aws.uploadsBucket = {$_ENV['S3_UPLOADS_BUCKET']}\n";
        }
        if (isset($_ENV['ELASTICACHE_HOST'])) {
            $config .= "server.memcache.host = {$_ENV['ELASTICACHE_HOST']}\n";
        }

        $config .= "server.replaceVars.remoteAddr.if = 172.*
        server.replaceVars.remoteAddr.replace = HTTP_X_FORWARDED_FOR
        \n";

        file_put_contents('config.local.ini', $config);
    }
}
