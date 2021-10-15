# Atomic Deployments

This project is based on the concepts presented in [Buddy Atomic Deployments](https://buddy.works/blog/introducing-atomic-deployments). It provides functionality to handle shared files and directories across deployments. While this was built for use with Buddy, it will work in any standard *nix environment.

## Dependencies

- PHP 5.5+
- curl

## File Structure

- `deploy-cache/` - the location where all files are uploaded to the server
- `revisions/` - a directory containing all revisions
- `current` - a symbolic link to the current revision
- `shared/` - a directory containing files that should be shared across all deploys

## Usage

```bash
curl -sS https://raw.githubusercontent.com/onedesign/atomic-deployments/master/atomic-deploy.php | php -- --revision=$(date "+%F-%H-%M-%S")
```

### Buddy + Craft 2 Example

Add the following in the "SSH Commands" section after your file upload action in your pipeline:

```
curl -sS https://raw.githubusercontent.com/onedesign/atomic-deployments/master/atomic-deploy.php | php -- --revision=${execution.to_revision.revision} --symlinks='{"shared/config/.env.php":".env.php","shared/storage":"craft/storage"}'
```

### Options

- `--revision` (**required**) accepts a string ID for this revision
- `--deploy-dir` accepts a base directory for deployments (default: current working directory)
- `--deploy-cache-dir` accepts a target cache directory (default: `deploy-cache` within deploy-dir)
- `--revisions-to-keep` number of old revisions to keep in addition to the current revision (default: `20`)
- `--symlinks` a JSON hash of symbolic links to be created in the revision directory (default: `{}`)
- `--help` prints help and usage instructions
- `--ansi` forces ANSI color output
- `--no-ansi` disables ANSI color output
- `--quiet` supresses unimportant messages

#### Symlinks

Symlinks are specified as `{"target":"linkname"}` and use the native `ln` utility to create links.

- `target` is relative to the `--deploy-dir` path
- `linkname` is relative to the revision path

For example, specifying this option:

```
--symlinks='{"shared/config/.env.php":".env.php","shared/logs":"logs"}'
```

will create symlinks the same way as:

```
ln -s <deploy-dir>/shared/config/.env.php revisions/<revision>/.env.php
ln -s <deploy-dir>/shared/logs revisions/<revision>/logs
```

**Note:** Files and directories that exist where the symlinks are being created will be overwritten. For example, using the above example, this is actually what is happening:

```
rm -rf revisions/<revision_id>/.env.php \
  && ln -sfn <deploy-dir>/shared/config/.env.php revisions/<revision>/.env.php
rm -rf revisions/<revision_id>/logs \
  && ln -sfn <deploy-dir>/shared/logs revisions/<revision>/logs
```

## Password Protection
By default, the deployment will password protect any site that is served from a *.oneis.us domain name. This works by prepending the contents of the `templates/htaccess-auth.txt` file to any existing `.htaccess` file found in the `current/web` directory. If an `.htaccess` file does not exist within that directory, one will be generated using the `templates/htaccess.txt` file.

## Testing

```bash
cd ./test
php ../bin/deploy \
  --deploy-cache-dir="./deploy-cache" \
  --revision="123456" \
  --symlinks='{"shared/config/env":".env","shared/storage":"storage"}'
```
