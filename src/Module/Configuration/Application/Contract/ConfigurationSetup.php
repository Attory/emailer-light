<?php

declare(strict_types=1);

namespace App\Module\Configuration\Application\Contract;

interface ConfigurationSetup
{
    public function saveOrganisation(string $installationId, OrganisationInput $input): void;

    public function testAndSaveMail(string $installationId, MailInput $input): void;

    public function savePolicies(PolicyInput $input): void;
}
