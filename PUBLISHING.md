# Publishing

This source tree is ready for a public repository named `cache-shield`. No GitHub repository was created in the preparation environment because no authenticated GitHub connection was available.

With GitHub CLI authenticated to the intended owner, from this directory:

```sh
gh repo create cache-shield --public --source=. --remote=origin --push
```

For an organization, use `gh repo create OWNER/cache-shield ...` instead. Review the repository, enable branch protection if desired, and wait for CI before tagging a release. Register the repository URL with Packagist to make `composer require cache-shield/cache-shield` available. The proposed Composer vendor name must be available or changed to your own vendor name before publication.
