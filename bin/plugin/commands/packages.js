/**
 * External dependencies
 */
const path = require( 'path' );
const glob = require( 'fast-glob' );
const fs = require( 'fs' );
const semver = require( 'semver' );
const readline = require( 'readline' );

/**
 * Internal dependencies
 */
const { log, formats } = require( '../lib/logger' );
const { askForConfirmation, runStep, readJSONFile } = require( '../lib/utils' );
const {
	runGitRepositoryCloneStep,
	runCleanLocalFoldersStep,
	findReleaseBranchName,
} = require( './common' );
const git = require( '../lib/git' );

/**
 * Checks out the WordPress release branch and syncs it with the changes from
 * the last plugin release.
 *
 * @param {string} gitWorkingDirectoryPath Git working directory path.
 * @param {string} abortMessage            Abort Message.
 *
 * @return {Promise<Object>} WordPress release branch.
 */
async function runWordPressReleaseBranchSyncStep(
	gitWorkingDirectoryPath,
	abortMessage
) {
	const wordpressReleaseBranch = 'wp/trunk';
	await runStep(
		'Getting into the WordPress release branch',
		abortMessage,
		async () => {
			const packageJsonPath = gitWorkingDirectoryPath + '/package.json';
			const pluginReleaseBranch = findReleaseBranchName(
				packageJsonPath
			);

			// Creating the release branch
			await git.checkoutRemoteBranch(
				gitWorkingDirectoryPath,
				wordpressReleaseBranch
			);
			log(
				'>> The local release branch ' +
					formats.success( wordpressReleaseBranch ) +
					' has been successfully checked out.'
			);

			await askForConfirmation(
				`The branch is ready for sync with the latest plugin release changes applied to "${ pluginReleaseBranch }". Proceed?`,
				true,
				abortMessage
			);

			await git.replaceContentFromRemoteBranch(
				gitWorkingDirectoryPath,
				pluginReleaseBranch
			);

			await git.commit(
				gitWorkingDirectoryPath,
				`Merge changes published in the Gutenberg plugin "${ pluginReleaseBranch }" branch`
			);

			log(
				'>> The local WordPress release branch ' +
					formats.success( wordpressReleaseBranch ) +
					' has been successfully synced.'
			);
		}
	);

	return {
		releaseBranch: wordpressReleaseBranch,
	};
}

/**
 * Update CHANGELOG files with the new version number for those packages that
 * contain new entries.
 *
 * @param {string} gitWorkingDirectoryPath Git working directory path.
 * @param {string} minimumVersionBump      Minimum version bump for the packages.
 * @param {string} abortMessage            Abort Message.
 */
async function updatePackageChangelogs(
	gitWorkingDirectoryPath,
	minimumVersionBump,
	abortMessage
) {
	const changelogFiles = await glob(
		path.resolve( gitWorkingDirectoryPath, 'packages/*/CHANGELOG.md' )
	);
	const processedPackages = await Promise.all(
		changelogFiles.map( async ( changelogFile ) => {
			const fileStream = fs.createReadStream( changelogFile );

			const lines = readline.createInterface( {
				input: fileStream,
			} );

			let changesDetected = false;
			let versionBump = null;
			for await ( const line of lines ) {
				const lineNormalized = line.toLowerCase();
				// Detect unpublished changes first.
				if ( lineNormalized.startsWith( '## unreleased' ) ) {
					changesDetected = true;
					continue;
				}

				// Skip all lines until unpublished changes found.
				if ( ! changesDetected ) {
					continue;
				}

				// A previous published version detected. Stop processing.
				if ( lineNormalized.startsWith( '## ' ) ) {
					break;
				}

				// A major version bump required. Stop processing.
				if ( lineNormalized.startsWith( '### Breaking Change' ) ) {
					versionBump = 'major';
					break;
				}

				// A minor version bump required. Proceed to the next line.
				if (
					lineNormalized.startsWith( '### deprecation' ) ||
					lineNormalized.startsWith( '### enhancement' ) ||
					lineNormalized.startsWith( '### new feature' )
				) {
					versionBump = 'minor';
					continue;
				}

				// A version bump required. Found new changelog section.
				if (
					versionBump !== 'minor' &&
					lineNormalized.startsWith( '### ' )
				) {
					versionBump = minimumVersionBump;
				}
			}
			const packageName = `@wordpress/${
				changelogFile.split( '/' ).reverse()[ 1 ]
			}`;
			const { version } = readJSONFile(
				changelogFile.replace( 'CHANGELOG.md', 'package.json' )
			);
			const nextVersion =
				versionBump !== null
					? semver.inc( version, versionBump )
					: null;

			return {
				changelogFile,
				packageName,
				version,
				nextVersion,
			};
		} )
	);

	const changelogsToUpdate = processedPackages.filter(
		( { nextVersion } ) => nextVersion
	);

	if ( changelogsToUpdate.length === 0 ) {
		log( '>> No changes in CHANGELOG files detected.' );
		return;
	}

	log(
		'>> Recommended version bumps based on the changes detected in CHANGELOG files:'
	);

	const publishDate = new Date().toISOString().split( 'T' )[ 0 ];
	await Promise.all(
		changelogsToUpdate.map(
			async ( { changelogFile, packageName, nextVersion, version } ) => {
				const content = await fs.promises.readFile(
					changelogFile,
					'utf8'
				);
				await fs.promises.writeFile(
					changelogFile,
					content.replace(
						'## Unreleased',
						`## Unreleased\n\n## ${ nextVersion } (${ publishDate })`
					)
				);
				log(
					`   - ${ packageName }: ${ version } -> ${ nextVersion }`
				);
			}
		)
	);

	await askForConfirmation(
		`All corresponding files were updated. Commit the changes?`,
		true,
		abortMessage
	);
	git.commit( gitWorkingDirectoryPath, 'Update changelog files', [ './*' ] );
	log( '>> Changelog files changes have been committed successfully.' );
}

/**
 * Push the local Git Changes and Tags to the remote repository.
 *
 * @param {string} gitWorkingDirectoryPath Git working directory path.
 * @param {string} releaseBranch           Release branch name.
 * @param {string} abortMessage            Abort message.
 */
async function runPushGitChangesStep(
	gitWorkingDirectoryPath,
	releaseBranch,
	abortMessage
) {
	await runStep(
		'Pushing the release branch and the tag',
		abortMessage,
		async () => {
			await askForConfirmation(
				'The release branch and the tag are going to be pushed to the remote repository. Continue?',
				true,
				abortMessage
			);
			await git.pushBranchToOrigin(
				gitWorkingDirectoryPath,
				releaseBranch
			);
			await git.pushTagsToOrigin();
		}
	);
}

/**
 * Prepublish to npm steps for WordPress packages.
 *
 * @param {string} minimumVersionBump Minimum version bump for the packages.
 *
 * @return {Promise<Object>} Github release object.
 */
async function prepublishPackages( minimumVersionBump ) {
	// This is a variable that contains the abort message shown when the script is aborted.
	let abortMessage = 'Aborting!';
	const temporaryFolders = [];
	await askForConfirmation( 'Ready to go? ' );

	// Cloning the Git repository.
	const gitWorkingDirectoryPath = await runGitRepositoryCloneStep(
		abortMessage
	);
	temporaryFolders.push( gitWorkingDirectoryPath );

	// Checking out the WordPress release branch and doing sync with the last plugin release.
	const { releaseBranch } = await runWordPressReleaseBranchSyncStep(
		gitWorkingDirectoryPath,
		abortMessage
	);

	await updatePackageChangelogs(
		gitWorkingDirectoryPath,
		minimumVersionBump,
		abortMessage
	);

	// Push the local changes
	abortMessage = `Aborting! Make sure to push changes applied to WordPress release branch "${ releaseBranch }" manually.`;
	await runPushGitChangesStep(
		gitWorkingDirectoryPath,
		releaseBranch,
		abortMessage
	);

	abortMessage = 'Aborting! The release is finished though.';
	await runCleanLocalFoldersStep( temporaryFolders, abortMessage );
}

async function prepublishNpmStablePackages() {
	log(
		formats.title(
			'\n💃 Time to publish WordPress packages to npm 🕺\n\n'
		),
		'Welcome! This tool is going to help you with prepublish to npm steps for the next stable version of WordPress packages.\n',
		"To perform a release you'll have to be a member of the WordPress Team on npm.\n"
	);

	await prepublishPackages( 'minor' );

	log(
		'\n>> 🎉 WordPress packages are ready to publish.\n',
		'Thanks for performing the prepublish process! You still need to run "npm run publish:prod" to perform the actual release.\n',
		'Let also people know on WordPress Slack when everything is finished.\n'
	);
}

module.exports = { prepublishNpmStablePackages };
