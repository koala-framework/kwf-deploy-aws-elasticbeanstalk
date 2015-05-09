<?php
namespace Kwf\DeployEb;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class DbDumpCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('dbdump')
            ->setDescription('XXX')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        \Kwf_Setup::setUp();

        $dbConfig = \Kwf_Registry::get('dao')->getDbConfig();
        $cacheTables = \Kwf_Util_ClearCache::getInstance()->getDbCacheTables();

        $dumpCmd = "mysqldump";
        $dumpCmd .= " --host=".escapeshellarg($dbConfig['host']);
        $dumpCmd .= " --user=".escapeshellarg($dbConfig['username']);
        $dumpCmd .= " --password=".escapeshellarg($dbConfig['password']);

        $cmd = $dumpCmd;
        foreach ($cacheTables as $t) {
            $cmd .=" --ignore-table=".escapeshellarg($dbConfig['dbname'].'.'.$t);
        }
        $cmd .= " $dbConfig[dbname]";
        passthru($cmd, $ret);
        if ($ret) return $ret;

        foreach ($cacheTables as $t) {
            $cmd = $dumpCmd;
            $cmd .= " --no-data ".escapeshellarg($dbConfig['dbname'])." ".escapeshellarg($t);
            passthru($cmd, $ret);
            if ($ret) return $ret;
        }
        return 0;
    }
}
