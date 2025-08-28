# Streamline Your Workflow: Build & Deployment with Winter Boot!

Winter Boot empowers you with a robust and flexible build and deployment system, leveraging the industry-standard **[Phing](https://www.phing.info/)** build tool. Get ready to automate your releases and deploy your microservices with confidence!

## Getting Started with Phing

To begin your journey with automated builds, ensure you have Phing installed and ready to go!

-   **Phing Version:** Make sure you're using Phing version `3.0.0` or greater.

-   **Download Phing:** Grab the latest Phar file from the official [Phing releases page](https://github.com/phingofficial/phing/releases/).

-   **Install Phing:**
    Copy the downloaded Phar file to a globally accessible location:
    ```shell
    cp phing-3.0.0-RC2.phar /usr/local/bin/
    ```
    Create a symbolic link to make the `phing` command universally available:
    ```shell
    ln -s /usr/local/bin/phing-3.0.0-RC2.phar /usr/bin/phing
    ```

-   **Verify Installation:** Now, you should be able to run `phing` from any directory!


#### Unlock Powerful Deployment Features

Winter Boot's build system offers a comprehensive suite of features to package and deploy your microservices:

1.  **Phar Binary Support:** Package your entire application into a single, executable Phar archive for easy distribution.
2.  **Docker Image Support:** Seamlessly build Docker images, enabling containerized deployments for consistency and scalability.
3.  **RPM Binary Support:** Generate RPM packages for streamlined installation and management on Linux systems.
4.  **`init.d` Script Support:** Automatically create `init.d` scripts for traditional service management.


## Configuring Your Build with Phing

Winter Boot integrates custom Phing tasks to simplify your build process. Let's set up your `build.properties` and `build.xml` files.

#### `build.properties`: Define Your Application's Metadata

This file holds essential metadata about your application, which will be used throughout the build process.

```text
app.id=example-service
app.version=1.0.0
app.release=DEV
app.group=Development/Services
app.summary=My Example Application
app.url=https://www.your.url
app.license=Your License

company.name=Example Company
```


#### `build.xml`: Your Build Orchestrator

This is where the magic happens! In your `build.xml` file, you'll define the targets and tasks for building your application. Remember to include the Winter Boot Phing tasks!

```xml
<property file="build.properties"/>

<!-- This is mandatory: Include Winter Boot's custom Phing tasks -->
<includepath classpath="./vendor/suvera/winter-boot/build/phing"/>

<property name="buildFileName" value="${app.id}-${app.version}-${app.release}"/>


<!-- Add Winter Phing Tasks for advanced build capabilities -->
<taskdef name="RpmBuild" classname="RpmBuildTask"/>
<taskdef name="WinterPhar" classname="WinterPharTask"/>
<taskdef name="Rmdir" classname="RmdirTask"/>

```


### 1. Create a Single Executable: Phar Binary

Package your entire microservice into a self-contained Phar archive. This is perfect for easy distribution and deployment!

Create a new Phing target, for example, named `phar`:

```xml
<fileset dir="." id="phpSources" defaultexcludes="true">
    <include name="src/**"/>
    <include name="vendor/**"/>

    <exclude name="**/vendor/phpunit/**"/>
    <exclude name="**/vendor/sebastian/**"/>
    <exclude name="**/.git/**"/>
    <exclude name="**/.github/**"/>
</fileset>

<target name="phar" description="Build Phar file">
    <echo>Building PHAR ...</echo>

    <mkdir dir="target/phar"/>

    <WinterPhar
        basedir="./"
        topDir="target/phar"
        name="${app.id}"
        version="${app.version}"
        release="${app.release}"
        summary="${app.summary}"
        outFileProperty="phar.Filename"
    >
        <!-- Define your service's startup script. See example: https://github.com/suvera/winter-example-service/tree/master/bin -->
        <Stub name="service" scriptPath="bin/example-service.php"/>

        <fileset refid="phpSources"/>
        
        <metadata>
            <element name="version" value="${app.version}"/>
            <element name="authors">
                <element name="${company.name}"/>
            </element>
        </metadata>
    </WinterPhar>
    
    <echo>PHAR Generated!</echo>
</target>

```

To generate your Phar file, simply run:

```shell
phing phar
```


### 2. Containerize Your Application: Docker Image

Build Docker images for your microservice, enabling consistent and isolated deployments across various environments.

Create a new Phing target, for example, named `docker`:

```xml
<target name="docker" description="Build Docker Image" depends="phar">
    <echo>Building Docker Image ...</echo>
    <exec dir="." executable="docker" level="verbose" checkreturn="true" passthru="true">
        <arg line="build . -t ${company.id}/${app.id}:${app.version}-${app.release} -f ./Dockerfile"/>
    </exec>
    <echo>Docker Image Generated!</echo>
</target>
```


To generate your Docker Image, execute:

```shell
phing docker
```

**Example `Dockerfile`:**

This `Dockerfile` demonstrates how to build a Docker image for your Winter Boot application, leveraging a base image and incorporating your generated Phar file.

```yaml
#####################################################################################
#  Build Application Image - Run below command
#     docker build . -t yourname/example-service:1.0.0 -f ./Dockerfile
######################################################################################
FROM suvera/winter-boot:latest

USER root
LABEL maintainer="yourname@example.com"

RUN useradd -ms /bin/bash app && mkdir -p /home/app/lib && mkdir -p /home/app/config

COPY ./target/phar/example-service-*.phar /home/app/lib/example-service.phar
COPY ./config/* /home/app/config/

RUN chown -R app /home/app

USER app
WORKDIR /home/app

ENTRYPOINT ["php", "/home/app/lib/example-service.phar", "-c", "/home/app/config"]

EXPOSE 8080
```


### 3. Package for Linux: RPM Binary

For Linux-based deployments, Winter Boot allows you to generate RPM packages, simplifying installation and system integration.

Create a new Phing target, for example, named `rpm`:

```xml
<target name="rpm" description="Build RPM" depends="phar">
    <echo>Building RPM ...</echo>

    <mkdir dir="target/scripts"/>
    <mkdir dir="target/rpm"/>

    <RpmBuild
        topDir="target/rpm"
        name="${app.id}"
        version="${app.version}"
        release="${app.release}"
        group="${app.group}"
        distribution=""
        license="${app.license}"
        url="${app.url}"
        summary="${app.summary}"
        defaultDirmode="755"
        defaultFilemode="644"
        defaultUsername="root"
        defaultGroupname="root"
    >

        <!-- Generate an init.d script for service management -->
        <InitDFile
            destFile="target/scripts/${app.id}"
            serviceName="${app.id}"
            appBinary="/usr/local/${app.id}/${phar.Filename}.phar"
            configDir="/etc/${app.id}"
            adminPort="9091"
            adminTokenFile=""
            logFile="/var/log/${app.id}.log"
            pidFile="/var/run/${app.id}.pid"
            username="root"
            installDir="/etc/init.d"
        />

        <RpmFile localFile="target/phar/${phar.Filename}.phar" installDir="/usr/local/${app.id}"/>
    </RpmBuild>
</target>
```


To generate your RPM package, run:

```shell
phing rpm
```


Your RPM file will be conveniently generated in the `target/rpm/RPMS/` directory.

-   **Automated Installation:** Installing the RPM will automatically deploy your Phar file and the generated `init.d` script.
-   **Effortless Service Management:** Start, stop, check status, or restart your service using the `init.d` script:

```shell

/etc/init.d/example-service start

/etc/init.d/example-service stop

/etc/init.d/example-service status

/etc/init.d/example-service restart

```

For a complete, working example, refer to the [example-service build.xml](https://github.com/suvera/winter-example-service).

