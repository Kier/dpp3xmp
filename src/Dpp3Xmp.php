<?php

class Dpp3Xmp
{
	const CREATE_FILES = true;

	const REFERENCE_PATH = '/reference/';

	const VERSION = '1.0.0 Beta';

	public static array $rawTypes = ['CRW', 'CR2', 'CR3'];

	/** @var array */
	protected array $cameras = [];

	/** @var null|SplFileInfo  */
	protected SplFileInfo|null $file = null;

	protected $exif;

	protected $dir = null;

	protected int $tempMin;
	protected int $tempMax;
	protected int $tempStep;

    public function __construct($dir, int $tempStep = 50, int $tempMin = 2500, int $tempMax = 10000)
    {
		$this->dir = $dir;

		$this->tempMin = $tempMin;
		$this->tempMax = $tempMax;
		$this->tempStep = $tempStep;

		$this->write();
		$this->write("Canon Digital Photo Professional 3.x recipe to XMP converter " . self::VERSION);
    }

	public function convertDPPtoXMP($root): bool
	{
		if (empty($root) || !is_dir($root))
		{
			$this->write("Root folder {$root} not found.");
			return false;
		}

		$di = new \RecursiveDirectoryIterator($root);
		$iterator = new \RecursiveIteratorIterator($di, RecursiveIteratorIterator::SELF_FIRST);
		$lastPath = '';

		/** @var SplFileInfo $file */
		foreach ($iterator AS $file)
		{
			if (!$file->isFile() || !in_array(strtoupper($file->getExtension()), self::$rawTypes))
			{
				continue;
			}

			$path = $file->getPathInfo();
			if ($path != $lastPath)
			{
				$this->write();
				$this->write($path . '/');
				$lastPath = $path;
			}

			$this->writeFileIntro($file);

			$xmp = $this->getXMP($file, $rggb, $wbAdj, $wb, $k, $exp);

			if (!is_string($xmp))
			{
				$this->write('(no recipe)', 1, '');
				continue;
			}

			$this->write(number_format($k) . ' K / ' . $wb . ($exp ? "; {$exp}ev" : ''), 1, '');

			$fp = fopen($file->getPathInfo() . '/' . $file->getBasename('.' . $file->getExtension()) . '.xmp', 'w');
			fwrite($fp, $xmp);
			fclose($fp);
		}

		$this->write();
		$this->write('All done.');

		return true;
	}

	public function buildReference(SplFileInfo $file, $exif = null, &$error = ''): ?string
	{
		if (is_null($exif))
		{
			$exif = $this->getExif($file);
		}

		if (!$this->hasRecipe($exif))
		{
			$error = "No DPP3 recipe in {$file->getFilename()}, unable to continue.";
			return false;
		}

		$this->file = $file;

		$this->write(sprintf("Building white balance reference for %s%s serial %s",
			$exif->CanonModelID,
			($exif->OwnerName ? " ({$exif->OwnerName})" : ''),
			$exif->SerialNumber
		), 2);

		$folder = $this->dir . self::REFERENCE_PATH . '/' . $this->getCameraId($exif);
		$extension = strtoupper($file->getExtension());

		if (!is_dir($folder))
		{
			mkdir($folder);
		}

		foreach (glob("{$folder}/*.{$extension}") AS $filename)
		{
			unlink($filename);
		}

		$this->write("Generating reference photos...");

		for ($temperature = $this->tempMin; $temperature <= $this->tempMax; $temperature += $this->tempStep)
		{
			$pathname = $file->getPathname();
			$newPathname = "{$folder}/{$temperature}.{$extension}";
			if (file_exists($newPathname))
			{
				unlink($newPathname);
			}

			`exiftool -CanonVRD:WhiteBalanceAdj=Kelvin -CanonVRD:WBAdjColorTemp={$temperature} {$pathname}`;

			rename($pathname, $newPathname);
			rename("{$pathname}_original", $pathname);

			$this->writeTemperatureProgress($temperature);
		}

		do
		{
			$this->write('', 2);
			$this->write("Open the folder " . realpath($folder) . " in DPP3,");
			$this->write("select all reference photos and run File > Save.", 2);
			$ok = $this->read("Type 'ok' when photos are saved in DPP3: ");
		}
		while (strtoupper($ok) != 'OK');
		$this->write();

		$json = [
			'CanonModelID' => $exif->CanonModelID,
			'OwnerName' => $exif->OwnerName,
			'SerialNumber' => $exif->SerialNumber,
			'data' => []
		];

		$this->write("Reading white balance data...");

		for ($temperature = $this->tempMin; $temperature <= $this->tempMax; $temperature += $this->tempStep)
		{
			$photo = new SplFileInfo("{$folder}/{$temperature}.{$extension}");
			$photoExif = $this->getExif($photo);

			if ($photoExif->WhiteBalanceAdj != 'Kelvin' || $photoExif->WBAdjColorTemp != $temperature)
			{
				Dpp3Xmp::write("Photo {$photo->getFilename()} should be set to color temperature = {$temperature}k in DPP3, but is actually set to {$photoExif->WBAdjColorTemp}k.");
				exit;
			}

			$this->writeTemperatureProgress($temperature);

			$json['data'][$temperature] = $this->getMultipliersFromRGGB($photoExif->WBAdjRGGBLevels);
		}

		$jsonFile = "{$this->dir}/cameras/{$this->getCameraId($exif)}.json";
		$fp = fopen($jsonFile, 'w');
		$jsonText = preg_replace('/("\d{4,5}":\[)/', "\n\$1", json_encode($json));
		fwrite($fp, $jsonText);
		fclose($fp);

		$this->write('', 2);
		$this->write("White balance reference data for {$exif->CanonModelID} serial {$exif->SerialNumber} generated.");
		$this->write("You may now delete " . realpath($folder), 2);

		return $jsonFile;
	}

	protected function writeTemperatureProgress($temperature)
	{
		$done = ($temperature - $this->tempMin) / $this->tempStep + 1;
		$total = ($this->tempMax - $this->tempMin) / $this->tempStep + 1;

		$this->write($this->progress($done, $total, sprintf("\t%' 5d.%s ", $temperature, $this->file->getExtension())), 0, '');
	}

    public function getXMP(SplFileInfo $file, &$WBAdjRGGBLevels = '', &$WhiteBalanceAdj = '', &$whiteBalance = '', &$kelvin = '', &$exposure = ''): ?string
    {
		$this->file = $file;
        $this->exif = $this->getExif($file);

        if (!$this->hasRecipe($this->exif))
        {
            // no recipe data - nothing to do
            return null;
        }

		$WBAdjRGGBLevels = $this->exif->WBAdjRGGBLevels;
		$WhiteBalanceAdj = $this->exif->WhiteBalanceAdj;
        $whiteBalance = $this->getWhiteBalance($kelvin);
		$attributes =
			$this->getTemperature($kelvin) .
			$this->getExposure($exposure) .
			$this->getContrast($contrast) .
			$this->getSaturation($saturation) .
			$this->getSharpness($sharpness);

		if (!$kelvin && !$exposure)
		{
			// no exposure tweaks, no white balance adjustments - nothing to do
			return null;
		}

		return '<x:xmpmeta xmlns:x="adobe:ns:meta/" x:xmptk="Adobe XMP Core 7.0-c000 1.000000, 0000/00/00-00:00:00">
	<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
		<rdf:Description rdf:about="" xmlns:crs="http://ns.adobe.com/camera-raw-settings/1.0/"
			WBAdjRGGBLevels="' . $WBAdjRGGBLevels . '"
			crs:Version="15.3"
			crs:ProcessVersion="11.0"
			crs:WhiteBalance="' . $whiteBalance . '"' . $attributes . '
			crs:LensProfileEnable="1"
			crs:ToneCurveName2012="Linear"
			crs:HasSettings="True"
			crs:AlreadyApplied="False">
		</rdf:Description>
	</rdf:RDF>
</x:xmpmeta>';
		
    }

    public function getExif(SplFileInfo $file)
    {
        $json = `exiftool -j -SerialNumber -OwnerName -CanonModelID -CanonVRD:all "{$file->getPathName()}"`;
        return json_decode($json)[0];
    }

	protected function hasRecipe($exif): bool
	{
		return isset($exif->VRDVersion);
	}

    protected function getClosestKelvin(): int|null
    {
        if (!isset($this->exif->WBAdjRGGBLevels))
        {
            return null;
        }

        $recipeMultipliers = $this->getMultipliersFromRGGB($this->exif->WBAdjRGGBLevels);

        $minDiff = PHP_FLOAT_MAX;
        $closestKelvin = null;

        foreach ($this->getCameraMultipliers() AS $kelvin => $refMultipliers)
        {
            $rgb = 0;

            for ($i = 0; $i <= 2; $i++)
            {
                $rgb += pow($recipeMultipliers[$i] - $refMultipliers[$i], 2);
            }

            $diff = sqrt($rgb);

            if ($diff < $minDiff)
            {
                $minDiff = $diff;
                $closestKelvin = $kelvin;
            }

			// TODO: abandon if we start to get a bigger difference, indicating that we are getting further away?
        }

        return $closestKelvin + 0;
    }

    public function getMultipliersFromRGGB(string $exifString): array
    {
        $rggb = array_map(function($value)
        {
            return intval($value);
        }, explode(' ', $exifString));

        return [$rggb[0], $rggb[1], $rggb[3]];
    }
	protected function getCameraMultipliers(): array
	{
		$cameraId = $this->getCameraId($this->exif);

		if (!isset($this->cameras[$cameraId]))
		{
			$file = "{$this->dir}/cameras/{$cameraId}.json";

			if (!file_exists($file))
			{
				$camera = "{$this->exif->CanonModelID} serial {$this->exif->SerialNumber}";

				$this->write('White balance reference required...', 2, '');

				$file = $this->buildReference($this->file, $this->exif, $error);
				if ($error)
				{
					$this->write($error);
					exit;
				}

				$this->writeFileIntro($this->file);
			}

			$this->cameras[$cameraId] = $this->getCameraMultipliersFromFile($file);
		}

		return $this->cameras[$cameraId];
	}

    protected function getCameraMultipliersChoice(): array
    {
        $cameraId = $this->getCameraId($this->exif);

        if (!isset($this->cameras[$cameraId]))
        {
            $file = "{$this->dir}/cameras/{$cameraId}.json";

            if (!file_exists($file))
            {
	            $camera = "{$this->exif->CanonModelID} serial {$this->exif->SerialNumber}";
				$this->write(" Unknown camera " . $camera, 2, '');

				do
				{
					$this->write();

					$knownFiles = $this->getKnownCameraFiles();

					foreach ($knownFiles as $i => $data)
					{
						$this->write(sprintf("%' 3d) %s", $i + 1, $data['name']));
					}

					$this->write();

					$this->write("Type '0' to generate white balance data for {$camera},");
					$this->write("or select one of the cameras above to use as a substitute for {$camera},");
					$choice = trim($this->read("or type 'r' to scan available cameras again: "));
				}
				while ($choice !== '0' && !key_exists(intval($choice) - 1, $knownFiles));

				if ($choice === '0')
				{
					$file = $this->buildReference($this->file, $this->exif, $error);
					if ($error)
					{
						$this->write($error);
						exit;
					}
				}
				else
				{
					$file = $knownFiles[intval($choice)]['file'];
				}

				$this->write('- ' . $this->file->getPathname(), 0);
            }

			$this->cameras[$cameraId] = $this->getCameraMultipliersFromFile($file);
        }

        return $this->cameras[$cameraId];
    }

    protected function getKnownCameraFiles()
    {
        $dirIterator = new \DirectoryIterator("{$this->dir}/cameras");
        $iterator = new IteratorIterator($dirIterator);

        $files = [];

        /** @var SplFileInfo $file */
        foreach ($iterator AS $file)
        {
			if ($file->isFile() && $file->getExtension() == 'json')
			{
				$data = json_decode(file_get_contents($file->getPathname()));
				$files[] = [
					'file' => $file->getPathname(),
					'name' => "{$data->CanonModelID} serial {$data->SerialNumber}"
				];
			}
        }

        return $files;
    }

    public function getCameraId($exif): string
    {
        $id = preg_replace('/[^a-z0-9_-]/i', '-', $exif->CanonModelID) . '.' . $exif->SerialNumber;
		return preg_replace('/-+/', '-', $id);
    }

	protected function getCameraMultipliersFromFile(string $path): array
	{
		// TODO: error handling
		return json_decode(file_get_contents($path), true)['data'];
	}

    protected function getWhiteBalance(&$kelvin): string
    {
        switch ($this->exif->WhiteBalanceAdj)
        {
            case 'Cloudy': // $kelvin = 6000;
            case 'Daylight': // $kelvin = 5200;
            case 'Flash': // $kelvin = 6000;
            case 'Fluorescent': // $kelvin = 4000;
            case 'Shade': // $kelvin = 7000;
            case 'Tungsten': // $kelvin = 3200;
                $kelvin = $this->getClosestKelvin();
                return $this->exif->WhiteBalanceAdj;

            case 'Manual (Click)':
                $kelvin = $this->getClosestKelvin();
                return 'Custom';

            case 'Kelvin':
                $kelvin = $this->exif->WBAdjColorTemp;
                return 'Custom';

            case 'Shot Settings':
                $kelvin = null;
                return 'As Shot';

            default:
                return 'Auto';
        }
    }

    protected function addSymbol($value): string
    {
        return ($value > 0 ? '+' : '') . $value;
    }

    protected function getAttribute($attribute, $value)
    {
        return "\n\t\t\t{$attribute}=\"{$value}\"";
    }

    protected function getTemperature(&$value)
    {
        if (is_integer($value))
        {
            return $this->getAttribute('crs:Temperature', $value) . $this->getAttribute('crs:Tint', 0);
        }

        return '';
    }

    protected function getExposure(&$value)
    {
        if (isset($this->exif->RawBrightnessAdj))
        {
            $value = $this->exif->RawBrightnessAdj + 0;
            $value = $this->addSymbol($value);

            return $this->getAttribute('crs:Exposure2012', $value);
        }

        return '';
    }

    protected function getContrast(&$value = null)
    {
        if (isset($this->exif->CameraRawContrast))
        {
            $value = $this->exif->CameraRawContrast + 0;
            $value = $this->addSymbol($value / 4 * 100);

            return $this->getAttribute('crs:Contrast2012', $value);
        }

        return '';
    }

    protected function getSaturation(&$value = null)
    {
        if (isset($this->exif->CameraRawSaturation))
        {
            $value = $this->exif->CameraRawSaturation + 0;
            $value = $this->addSymbol($value / 4 * 100);

            return $this->getAttribute('crs:Saturation', $value);
        }

        return '';
    }

    protected function getSharpness(&$value = null)
    {
        if (isset($this->exif->CameraRawSharpness))
        {
            // 0 -> 10 to 0 -> 150
            $value = $this->exif->CameraRawSharpness + 0;
            $value = $value / 10 * 150;

            return $this->getAttribute('crs:Sharpness', $value);
        }

        return '';
    }

	public function write($text = '', $newLines = 1, $prefix = "\t"): void
	{
		print ($text ? $prefix : '') . $text . str_repeat(PHP_EOL, $newLines);
	}

	public function writeFileIntro(SplFileInfo $file): void
	{
		$this->write(sprintf('- %s -- ', $file->getFilename()), 0);
	}

	protected function progress($done, $total, $info = '', $width = 50, $off = '_', $on = '#'): string
	{
		$perc = round(($done * 100) / $total);
		$bar = round(($width * $perc) / 100);

		if ($bar > $width)  // Catch overflow where done > total
		{
			$bar = $width;
		}

		return sprintf("%s[%s%s] %3.3s%% %s/%s\r",
			$info,
			str_repeat( $on, $bar),
			str_repeat( $off, $width-$bar),
			$perc,
			$done,
			$total
		);
	}   //*** progress_bar() ****/

	public function read($text): bool|string
	{
		return readline("\t{$text}");
	}
}