<?php
namespace Kwf\DeployEb;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Aws\ElasticBeanstalk\ElasticBeanstalkClient;
use Aws\S3\S3Client;

class UploadS3Command extends Command
{
    protected function configure()
    {
        $this
            ->setName('upload')
            ->setDescription('Upload uploads to s3 bucket as configured for production section')
            ->addOption(
               'server',
               's',
               InputOption::VALUE_OPTIONAL,
                'Server (section) to upload for',
                'production'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!file_exists($_SERVER['HOME'].'/.aws/config')) {
            throw new \Exception("Can't get aws config, set up eb cli first");
        }
        $awsConfig = parse_ini_file($_SERVER['HOME'].'/.aws/config', true);
        $awsConfig = array(
            'key' => $awsConfig['profile eb-cli']['aws_access_key_id'],
            'secret' => $awsConfig['profile eb-cli']['aws_secret_access_key']
        );
        $s3 = new \Kwf_Util_Aws_S3($awsConfig);

        \Kwf_Setup::setUp();

        $prodSection = $input->getOption('server');
        $prodConfig = \Kwf_Config_Web::getInstance($prodSection);
        $bucket = $prodConfig->aws->uploadsBucket;
        if (!$bucket) {
            throw new \Exception("No aws.uploadBucket configured for '$prodSection'");
        }

        $model = \Kwf_Model_Abstract::getInstance('Kwf_Uploads_Model');
        $select = new \Kwf_Model_Select();
        $it = new \Kwf_Model_Iterator_Packages(
            new \Kwf_Model_Iterator_Rows($model, $select)
        );
        $it = new \Kwf_Iterator_ConsoleProgressBar($it);
        foreach ($it as $row) {
            $file = $row->getFileSource();
            if (file_exists($file)) {
                if ($s3->if_object_exists($bucket, $row->id)) {
                    echo "already existing: $row->id\n";
                } else {
                    echo "uploading: $row->id";
                    $contents = file_get_contents($file);
                    $r = $s3->create_object(
                        $bucket,
                        $row->id,
                        array(
                            'body' => $contents,
                            'length' => strlen($contents),
                            'contentType' => $row->mime_type,
                        )
                    );
                    if (!$r->isOk()) {
                        throw new \Exception($r->body);
                    }
                    echo " OK\n";
                }
            }
        }
    }
}
