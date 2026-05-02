module.exports = function( grunt ) {
	'use strict';

	grunt.initConfig( {
		pkg: grunt.file.readJSON( 'package.json' ),

		cssmin: {
			dist: {
				files: [
					{
						expand: true,
						cwd: 'assets/css/',
						src: [ '*.css', '!*.min.css' ],
						dest: 'assets/css/',
						ext: '.min.css',
					},
				],
			},
		},

		uglify: {
			dist: {
				options: { mangle: { reserved: [ 'jQuery' ] } },
				files: [
					{
						expand: true,
						cwd: 'assets/js/',
						src: [ '*.js', '!*.min.js' ],
						dest: 'assets/js/',
						ext: '.min.js',
					},
				],
			},
		},

		makepot: {
			target: {
				options: {
					domainPath: 'languages/',
					potFilename: 'wb-gamification.pot',
					type: 'wp-plugin',
					updateTimestamp: false,
				},
			},
		},

		clean: { dist: [ 'dist/' ] },

		copy: {
			dist: {
				files: [
					{
						expand: true,
						src: [
							'**',
							'!.git/**', '!.gitignore', '!.github/**', '!.wordpress-org/**',
							'!node_modules/**', '!tests/**', '!bin/**', '!dist/**',
							'!docs/**', '!plans/**', '!audit/**',
							'!phpunit.xml.dist', '!phpunit.xml',
							'!phpstan.neon.dist', '!phpstan-baseline.neon',
							'!phpcs.xml', '!.phpcs.xml',
							'!package.json', '!package-lock.json',
							'!composer.json', '!composer.lock',
							'!Gruntfile.js', '!CLAUDE.md', '!PLAN.md',
							'!**/*.md',
							'!vendor/bin/**', '!vendor/phpunit/**',
							'!vendor/squizlabs/**', '!vendor/wp-coding-standards/**',
							'!vendor/phpcompatibility/**', '!vendor/szepeviktor/**',
							'!vendor/phpstan/**', '!vendor/brain/**',
						],
						dest: 'dist/wb-gamification/',
					},
				],
			},
		},

		compress: {
			dist: {
				options: {
					archive: 'dist/wb-gamification-<%= pkg.version %>.zip',
					mode: 'zip',
				},
				files: [
					{
						expand: true,
						cwd: 'dist/',
						src: [ 'wb-gamification/**' ],
					},
				],
			},
		},
	} );

	grunt.loadNpmTasks( 'grunt-contrib-cssmin' );
	grunt.loadNpmTasks( 'grunt-contrib-uglify' );
	grunt.loadNpmTasks( 'grunt-wp-i18n' );
	grunt.loadNpmTasks( 'grunt-contrib-clean' );
	grunt.loadNpmTasks( 'grunt-contrib-copy' );
	grunt.loadNpmTasks( 'grunt-contrib-compress' );

	grunt.registerTask( 'build', [ 'cssmin', 'uglify', 'makepot' ] );
	grunt.registerTask( 'dist', [ 'build', 'clean:dist', 'copy:dist', 'compress:dist' ] );
	grunt.registerTask( 'default', [ 'build' ] );
};
