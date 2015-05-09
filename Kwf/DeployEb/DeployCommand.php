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

class DeployCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('deploy')
            ->setDescription('Deploy new version to current environment')
        ;
    }

    private function _getCurrentBranch()
    {
        exec('git status --porcelain --branch', $output);
        $tmp = explode(' ', $output[0]);
        $tmp = explode('...', $tmp[1]);
        return $tmp[0];
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $ebConfig = Yaml::parse(file_get_contents('.elasticbeanstalk/config.yml'));
        $applicationName = $ebConfig['global']['application_name'];
        $region = $ebConfig['global']['default_region'];
        $currentBranch = $this->_getCurrentBranch();
        if (!isset($ebConfig['branch-defaults'][$currentBranch]['environment'])) {
            $environmentName = null;
            $output->writeln("<comment>No environment set for '$currentBranch' in .elasticbeanstalk/config.yml</comment>");
            $output->writeln("<comment>Creating only new application version</comment>");
            $output->writeln("Set environment using 'eb use'");
        } else {
            $environmentName = $ebConfig['branch-defaults'][$currentBranch]['environment'];
        }

        $s3Bucket = $applicationName.'-eb-'.$region;
        $applicationVersion = trim(`git rev-parse --short HEAD`);
        $s3Key = "$applicationName-$applicationVersion.zip";

        $s3Client = S3Client::factory();
        $ebClient = ElasticBeanstalkClient::factory(array(
            'region' => $region
        ));

        $result = iterator_to_array($ebClient->getDescribeApplicationVersionsIterator(array(
            'ApplicationName' => $applicationName,
            'VersionLabels' => array($applicationVersion),
        )));
        if (count($result) > 0) {
            $output->writeln("<error>Applicaton Version '$applicationVersion' already exists for '$applicationName'</error>");
            $output->writeln("You should commit changes before deployment.");
            return 1;
        }

        $output->writeln("Creating zip...");
        $cmd = "./vendor/bin/deploy create-archive";
        $this->_systemCheckRet($cmd, $input, $output);

        if (!$s3Client->doesBucketExist($s3Bucket)) {
            $output->writeln("Creating S3 Bucket '$s3Bucket...");
            $s3Client->createBucket(array(
                'Bucket'             => $s3Bucket,
                'LocationConstraint' => $region
            ));
        }

        $output->writeln("Uploading zip...");
        $s3Client->upload($s3Bucket, $s3Key, file_get_contents('deploy.zip'));

        $output->writeln("creating application version...");
        $ebClient->createApplicationVersion(array(
            'ApplicationName' => $applicationName,
            'VersionLabel' => $applicationVersion,
            'SourceBundle' => array(
                'S3Bucket' => $s3Bucket,
                'S3Key' => $s3Key,
            ),
            'AutoCreateApplication' => false,
        ));

        if ($environmentName) {
            $output->writeln("Updating environment...");
            $ebClient->updateEnvironment(array(
                'EnvironmentName' => $environmentName,
                'VersionLabel' => $applicationVersion,
            ));
        } else {
            $output->writeln("Now update (or create) environment using AWS console and use application version '$applicationVersion'.");
        }
    }


    private function _systemCheckRet($cmd, InputInterface $input, OutputInterface $output)
    {
        $ret = null;
        if ($output->isDebug()) {
            $output->writeln($cmd);
        }
        passthru($cmd, $ret);
        if ($ret != 0) {
            throw new \Exception("command failed");
        }
    }
}
