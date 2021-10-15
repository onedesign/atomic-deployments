<?php
/**
 * Atomic Deployments
 *
 * @link      https://onedesigncompany.com
 * @copyright Copyright (c) 2021 One Design Company
 */


namespace onedesign\atomicdeploy;


/**
 * @author    One Design Company
 * @package   atomic-deployments
 * @since     1.1.0
 */
class PasswordProtect
{
    /**
     * Generate the .htaccess file
     *
     * @param string $deployDir Path to the deploy directory
     * @return bool
     */
    public static function generateHtaccessFile(string $deployDir): bool
    {
        $htpasswdPath = self::writeHtpasswdFile($deployDir);
        $outputPath = $deployDir . '/current/web/.htaccess';

        $authContents = @file_get_contents(TEMPLATE_DIR . '/htaccess-auth.txt');
        $authContents = str_replace('%{AUTH_FILE_PATH}', $htpasswdPath, $authContents);

        // If the project doesn't have an .htaccess file, create one from the template
        if (!file_exists($outputPath)) {
            $htaccessFileContents = @file_get_contents(TEMPLATE_DIR . '/htaccess.txt');
        } else {
            $htaccessFileContents = @file_get_contents($outputPath);
        }

        $resource = fopen($outputPath, 'w');
        fwrite($resource, $authContents . PHP_EOL . $htaccessFileContents);
        return fclose($resource);
    }

    /**
     * Generate the auth string to output in the .htpasswd file
     *
     * @return string Auth string
     */
    public static function generateAuthString(): string
    {
        $username = 'onedesign';
        $password = 'oneisus';

        $encrypted_password = crypt($password, base64_encode($password));
        return $username . ':' . $encrypted_password;
    }

    /**
     * Write the .htpasswd file to the DEPLOY_DIR
     *
     * @param string $deployDir Deploy directory
     * @return string Path to the .htpasswd file
     */
    public static function writeHtpasswdFile(string $deployDir): string
    {
        $authString = self::generateAuthString();
        $htpasswdPath = $deployDir . '/.htpasswd';

        $htpasswdFile = fopen($htpasswdPath, 'w');
        fwrite($htpasswdFile, $authString);
        fclose($htpasswdFile);

        return $htpasswdPath;
    }
}
