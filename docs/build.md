# Build & Deployment

Framework supports **[Phing](https://www.phing.info/)** build system

- Phing Version >= 3.0.0

- Download **Phar** file from [https://github.com/phingofficial/phing/releases/](https://github.com/phingofficial/phing/releases/)

- Copy Phar file to
```shell
cp phing-3.0.0-RC2.phar /usr/local/bin/
```
- Create symlink to bin directory

```shell
ln -s /usr/local/bin/phing-3.0.0-RC2.phar /usr/bin/phing
```

- Now **phing** command should work!


#### Supported Features

1. Phar binary support for your micro-service
2. Docker Image support
3. RPM binary support
4. init.d script support


## Phing

#### build.properties

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


#### build.xml

in **build.xml**, add following code

```xml
<property file="build.properties"/>

<!-- This is mandatory -->
<includepath classpath="./vendor/suvera/winter-boot/build/phing"/>

<property name="buildFileName" value="${app.id}-${app.version}-${app.release}"/>


<!-- Add Winter Phing Tasks -->
<taskdef name="RpmBuild" classname="RpmBuildTask"/>
<taskdef name="WinterPhar" classname="WinterPharTask"/>
<taskdef name="Rmdir" classname="RmdirTask"/>

```


### 1. Phar binary

Create a new Phing target,  name it with ex: **phar**

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
        <!-- Service Start-Up script, see example: https://github.com/suvera/winter-example-service/tree/master/bin -->
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

To generate Phar, run below command

```shell
phing phar
```


### 2. Docker Image

Create a new Phing target,  name it with ex: **rpm**

```xml
<target name="docker" description="Build Docker Image" depends="phar">
    <echo>Building Docker Image ...</echo>
    <exec dir="." executable="docker" level="verbose" checkreturn="true" passthru="true">
        <arg line="build . -t ${company.id}/${app.id}:${app.version}-${app.release} -f ./Dockerfile"/>
    </exec>
    <echo>Docker Image Generated!</echo>
</target>
```


To generate Docker Image, run below command

```shell
phing docker
```

**Dockerfile**
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


### 3. RPM binary

Create a new Phing target,  name it with ex: **rpm**

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

        <!-- to generate init.d script -->
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


To generate RPM, run below command

```shell
phing rpm
```


RPM file will be generated in the folders **target/rpm/RPMS/**

- Installing RPM will also install phar file as mentioned in above tasks
- Installing RPM will also install init.d script
- Start service with init.d script

```shell

/etc/init.d/example-service start

/etc/init.d/example-service stop

/etc/init.d/example-service status

/etc/init.d/example-service restart

```

See example [build.xml](https://github.com/suvera/winter-example-service)

